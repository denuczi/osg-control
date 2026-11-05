"""v6.0.1 regression tests. No Windows, network or service side effects."""
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import diagnostics_v6
import nodo_agent
from spool import LocalSpool


def event(record_id: int, *, provider='Microsoft-Windows-Kernel-Power', event_id=41):
    return dict(channel='System', record_id=record_id, event_id=event_id,
                provider=provider, level=2, observed_at='2026-11-02T10:00:00+00:00')


class EventSyncTests(unittest.TestCase):
    def test_rejected_event_does_not_block_valid_events_and_is_preserved(self):
        with tempfile.TemporaryDirectory() as temp:
            spool = LocalSpool(Path(temp) / 'outbox.sqlite')
            spool.record_system_events([event(1), event(2), event(3)])
            events = spool.pending_system_events()
            bad = events[0]['event_uuid']
            calls = []
            def api(server, path, method, *, token, payload):
                sent = payload['events']
                calls.append([e['event_uuid'] for e in sent])
                if bad in calls[-1]:
                    raise nodo_agent.ApiError(422, endpoint=path)
                return {'accepted_ids': [e['event_uuid'] for e in sent]}
            with patch.object(nodo_agent, 'call_api', side_effect=api):
                with self.assertRaises(nodo_agent.ApiError):
                    nodo_agent._sync_system_events(spool, 'https://server', 'token')
            pending = spool.pending_system_events()
            self.assertEqual([], pending)
            self.assertEqual(1, spool.deferred_system_event_count())
            self.assertGreater(len(calls), 1)
            self.assertEqual(1, spool.retry_deferred_samples())
            self.assertEqual([bad], [e['event_uuid'] for e in LocalSpool(spool.path).pending_system_events()])

    def test_reset_bookmark_and_events_commit_in_single_transaction(self):
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / 'events.sqlite'
            spool = LocalSpool(path)
            spool.record_system_events([event(400)])
            spool.record_system_events([event(2)], advances={'System':2}, resets={'System'})
            self.assertEqual(2, LocalSpool(path).system_event_bookmarks()['System'])
            self.assertEqual(2, len(spool.pending_system_events()))

    def test_rejected_event_deferral_survives_restart(self):
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / 'events.sqlite'
            spool = LocalSpool(path)
            spool.record_system_events([event(1)])
            uuid = spool.pending_system_events()[0]['event_uuid']
            spool.defer_system_event(uuid)
            self.assertEqual([], LocalSpool(path).pending_system_events())
            self.assertEqual(1, LocalSpool(path).deferred_system_event_count())

    def test_invalid_ack_preserves_entire_batch(self):
        with tempfile.TemporaryDirectory() as temp:
            spool = LocalSpool(Path(temp) / 'outbox.sqlite')
            spool.record_system_events([event(1), event(2)])
            first = spool.pending_system_events()[0]['event_uuid']
            with patch.object(nodo_agent, 'call_api', return_value={'accepted_ids': [first]}):
                with self.assertRaises(RuntimeError):
                    nodo_agent._sync_system_events(spool, 'https://server', 'token')
            self.assertEqual(2, len(spool.pending_system_events()))

    def test_bookmark_transactional_and_durable(self):
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / 'events.sqlite'
            spool = LocalSpool(path)
            spool.record_system_events([event(10), event(11)])
            self.assertEqual(11, LocalSpool(path).system_event_bookmarks()['System'])
            with self.assertRaises(OverflowError):
                spool.record_system_events([event(12)], max_pending=0)
            self.assertEqual(11, LocalSpool(path).system_event_bookmarks()['System'])


class WindowsEventCollectionTests(unittest.TestCase):
    def test_incremental_query_advances_from_bookmark_in_oldest_first_order(self):
        fake = {'Events':[dict(RecordId=102, EventId=41, Provider='Microsoft-Windows-Kernel-Power',
                               Time='2026-11-02T10:00:00+00:00', Channel='System', Level=2)],
                'LatestRecordId':103}
        scripts = []
        def query(script, timeout):
            scripts.append(script)
            return fake if "'System'" in script else {'Events':[], 'LatestRecordId':None}
        with patch.object(diagnostics_v6.os, 'name', 'nt'), patch.object(diagnostics_v6, '_windows_query', side_effect=query):
            records = diagnostics_v6.system_events({'System':101}).events
        self.assertEqual([102], [e['record_id'] for e in records])
        self.assertTrue(any('EventRecordID > ' in s and '$cursor=101' in s and '-Oldest' in s for s in scripts))

    def test_provider_mismatch_does_not_produce_misleading_event(self):
        response = {'Events':[dict(RecordId=111, EventId=41, Provider='SomeOtherProvider',
                                    Time='2026-11-02T10:00:00+00:00', Channel='System', Level=2)],
                    'LatestRecordId':111}
        with patch.object(diagnostics_v6.os, 'name', 'nt'), patch.object(diagnostics_v6, '_windows_query', return_value=response):
            self.assertEqual([], diagnostics_v6.system_events({}).events)

    def test_expired_event_log_range_is_detected(self):
        response = {'Events': [], 'OldestRecordId':250, 'LatestRecordId':400}
        with patch.object(diagnostics_v6.os, 'name', 'nt'), patch.object(diagnostics_v6, '_windows_query', return_value=response), \
             self.assertLogs('nodo-agent', level='WARNING') as captured:
            diagnostics_v6.system_events({'System':100})
        self.assertTrue(any('pérdida de cobertura' in line for line in captured.output))

    def test_saturation_warns_and_log_reset_is_explicit(self):
        records = [dict(RecordId=x, EventId=41, Provider='Microsoft-Windows-Kernel-Power',
                        Time='2026-11-02T10:00:00+00:00', Channel='System', Level=2)
                   for x in range(100,180)]
        response = {'Events':records, 'LatestRecordId':180}
        with patch.object(diagnostics_v6.os, 'name', 'nt'), patch.object(diagnostics_v6, '_windows_query', return_value=response), \
             self.assertLogs('nodo-agent', level='WARNING') as captured:
            result = diagnostics_v6.system_events({'System':90})
        self.assertTrue(result.saturated)
        self.assertTrue(any('80' in item for item in captured.output))
        with patch.object(diagnostics_v6.os, 'name', 'nt'), patch.object(diagnostics_v6, '_windows_query', return_value={'Events':[],'LatestRecordId':3,'Reset':True}), \
             self.assertLogs('nodo-agent', level='WARNING') as captured:
            diagnostics_v6.system_events({'System':400})
        self.assertTrue(any('reinici' in item.lower() or 'rotaci' in item.lower() for item in captured.output))


if __name__ == '__main__':
    unittest.main()
