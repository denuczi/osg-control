"""No network calls, no Windows service, no real counter modifications."""
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import patch
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import nodo_agent
from spool import LocalSpool
from network_usage import split_utc_hours, bucket_uuid


def dt(hour=10, minute=0):
    return datetime(2026, 11, 2, hour, minute, tzinfo=timezone.utc)


class UsageSpoolTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.db = Path(self.tmp.name) / 'agent.sqlite'
        self.spool = LocalSpool(self.db)

    def tearDown(self):
        self.tmp.cleanup()

    def test_first_baseline_then_diff_and_restart_persists(self):
        self.assertEqual(0, self.spool.record_usage('boot', {'Ethernet': (1_000, 100)}, dt()))
        self.assertEqual(1, self.spool.record_usage('boot', {'Ethernet': (2_000, 400)}, dt(10, 1)))
        reloaded = LocalSpool(self.db)
        pending = reloaded.pending_usage()
        self.assertEqual(len(pending), 1)
        self.assertEqual((pending[0]['rx_bytes'], pending[0]['tx_bytes']), (1_000, 300))
        self.assertEqual(pending[0]['observed_seconds'], 60)
        self.assertEqual(pending[0]['revision'], 1)

    def test_hour_crossing_preserves_total_even_with_rounding(self):
        self.spool.record_usage('boot', {'Ethernet': (100, 500)}, dt(10, 59))
        self.spool.record_usage('boot', {'Ethernet': (199, 549)}, dt(11, 1))
        items = self.spool.pending_usage()
        self.assertEqual(len(items), 2)
        self.assertEqual(sum(i['rx_bytes'] for i in items), 99)
        self.assertEqual(sum(i['tx_bytes'] for i in items), 49)
        self.assertEqual(sum(i['observed_seconds'] for i in items), 120)
        self.assertNotEqual(items[0]['bucket_uuid'], items[1]['bucket_uuid'])

    def test_revisions_and_only_exact_ack(self):
        self.spool.record_usage('b', {'Ethernet': (0, 0)}, dt())
        self.spool.record_usage('b', {'Ethernet': (100, 100)}, dt(10, 1))
        first = self.spool.pending_usage()[0]
        self.spool.record_usage('b', {'Ethernet': (200, 300)}, dt(10, 2))
        second = self.spool.pending_usage()[0]
        self.assertEqual(second['revision'], first['revision'] + 1)
        self.spool.acknowledge_usage([{'bucket_uuid': first['bucket_uuid'], 'revision': first['revision']}])
        self.assertEqual(len(self.spool.pending_usage()), 1)
        self.spool.acknowledge_usage([{'bucket_uuid': second['bucket_uuid'], 'revision': second['revision']}])
        self.assertEqual(self.spool.usage_pending_count(), 0)

    def test_reboot_counter_reset_and_gap_do_not_fabricate_consumption(self):
        self.spool.record_usage('b1', {'Ethernet': (1000, 1000)}, dt())
        self.spool.record_usage('b2', {'Ethernet': (20, 30)}, dt(10, 1))
        self.assertEqual(self.spool.usage_pending_count(), 0)
        self.spool.record_usage('b2', {'Ethernet': (500, 500)}, dt(10, 2))
        self.assertEqual(self.spool.pending_usage()[0]['rx_bytes'], 480)
        self.spool.record_usage('b2', {'Ethernet': (5, 6)}, dt(10, 3))
        self.assertEqual(self.spool.pending_usage()[0]['rx_bytes'], 480)
        self.spool.record_usage('b2', {'Ethernet': (999, 999)}, dt(14, 0))
        self.assertEqual(self.spool.usage_pending_count(), 1)

    def test_sync_sends_latest_and_retains_data_on_bad_confirmation(self):
        self.spool.record_usage('boot', {'Ethernet': (100, 100)}, dt())
        self.spool.record_usage('boot', {'Ethernet': (200, 300)}, dt(10, 1))
        with patch.object(nodo_agent, 'call_api', return_value={'accepted': []}):
            with self.assertRaises(RuntimeError):
                nodo_agent.sync_usage(self.spool, 'https://example.test', 'token')
        self.assertEqual(self.spool.usage_pending_count(), 1)
        def confirmed(server, endpoint, method, **kwargs):
            return {'accepted': [{k: b[k] for k in ('bucket_uuid', 'revision')}
                                 for b in kwargs['payload']['buckets']]}
        with patch.object(nodo_agent, 'call_api', side_effect=confirmed):
            nodo_agent.sync_usage(self.spool, 'https://example.test', 'token')
        self.assertEqual(self.spool.usage_pending_count(), 0)

    def test_one_invalid_hour_is_deferred_while_good_hour_syncs(self):
        self.spool.record_usage('boot', {'Ethernet': (0, 0)}, dt(10, 59))
        self.spool.record_usage('boot', {'Ethernet': (300, 300)}, dt(11, 1))
        buckets = self.spool.pending_usage()
        self.assertEqual(len(buckets), 2)
        def server(server, endpoint, method, **kwargs):
            payload = kwargs['payload']['buckets']
            if any(b['bucket_uuid'] == buckets[0]['bucket_uuid'] for b in payload):
                raise nodo_agent.ApiError(422, endpoint=endpoint, fields=('buckets.0.rx_bytes',))
            return {'accepted': [{k:b[k] for k in ('bucket_uuid','revision')} for b in payload]}
        with patch.object(nodo_agent, 'call_api', side_effect=server):
            nodo_agent.sync_usage(self.spool, 'https://example.test', 'token')
        self.assertEqual(self.spool.usage_pending_count(), 1)
        self.assertEqual(self.spool.pending_usage(), [])  # Deferred, not deleted
        self.assertEqual(len(self.spool.pending_usage(limit=32)), 0)

    def test_old_pending_is_retained_until_confirmed_then_pruned(self):
        self.spool.record_usage('boot', {'Ethernet': (0, 0)}, dt())
        self.spool.record_usage('boot', {'Ethernet': (100, 200)}, dt(10, 1))
        self.spool.record_usage('boot', {'Ethernet': (200, 400)}, dt(14, 0))
        self.assertEqual(self.spool.usage_pending_count(), 1)
        item = self.spool.pending_usage()[0]
        self.spool.acknowledge_usage([{'bucket_uuid':item['bucket_uuid'],'revision':item['revision']}])
        self.spool.record_usage('boot', {'Ethernet': (300, 500)}, dt(14, 1))
        self.assertEqual(len(self.spool.pending_usage()), 1)
        self.assertNotEqual(self.spool.pending_usage()[0]['bucket_uuid'], item['bucket_uuid'])

    def test_hour_split_exact_byte_conservation(self):
        chunks = list(split_utc_hours(dt(10, 59), dt(11, 1), 101, 55))
        self.assertEqual(sum(chunk[1] for chunk in chunks), 101)
        self.assertEqual(sum(chunk[2] for chunk in chunks), 55)


if __name__ == '__main__':
    unittest.main()
