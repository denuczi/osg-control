"""Passive removable-media event reconciliation and truthful historical metadata."""
import tempfile
import unittest
from datetime import datetime, timezone, timedelta
from pathlib import Path

from spool import LocalSpool


def stamp(delta=0):
    return (datetime.now(timezone.utc) + timedelta(seconds=delta)).isoformat(timespec='milliseconds')


def mounted(device='D:', *, label='Pendrive', serial='SANDISK-001', size=7_783_000_000):
    return {
        'device': device, 'mountpoint': device + '\\', 'media_class': 'usb',
        'label': label, 'serial': serial, 'filesystem': 'FAT32',
        'removable': True, 'total_bytes': size, 'used_bytes': 0, 'free_bytes': size,
    }


class MediaEnrichmentV543Tests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.spool = LocalSpool(Path(self.tmp.name) / 'spool.sqlite')
        self.spool.record_media_state([], stamp(-60))

    def test_mount_enriches_pending_arrival_with_same_event_id_and_timestamp(self):
        recorded_at = stamp()
        self.assertTrue(self.spool.record_media_notification('connected', 'D:', 'usb', recorded_at))
        pending = self.spool.pending_media_events()
        self.assertEqual(1, len(pending))
        self.assertEqual(0, pending[0]['volume']['total_bytes'])
        self.assertEqual([], self.spool.pending_media_events(ready_only=True))

        self.assertEqual(0, self.spool.record_media_state([mounted()], stamp()))
        after = self.spool.pending_media_events(ready_only=True)
        self.assertEqual(1, len(after))
        self.assertEqual(recorded_at, after[0]['observed_at'])
        self.assertEqual(pending[0]['event_uuid'], after[0]['event_uuid'])
        self.assertEqual('Pendrive', after[0]['volume']['label'])
        self.assertEqual(7_783_000_000, after[0]['volume']['total_bytes'])
        self.assertEqual('FAT32', after[0]['volume']['filesystem'])

    def test_ejection_uses_enriched_state_even_after_arrival_ack(self):
        self.spool.record_media_notification('connected', 'D:', 'usb', stamp())
        self.spool.record_media_state([mounted()], stamp())
        arrival_id = self.spool.pending_media_events()[0]['event_uuid']
        self.spool.acknowledge_media_events([arrival_id])
        self.spool.record_media_notification('disconnected', 'D:', 'usb', stamp())
        removal = self.spool.pending_media_events()
        self.assertEqual(1, len(removal))
        self.assertEqual('disconnected', removal[0]['kind'])
        self.assertEqual('Pendrive', removal[0]['volume']['label'])
        self.assertEqual(7_783_000_000, removal[0]['volume']['total_bytes'])

    def test_recent_arrival_is_not_falsely_disconnected_before_windows_mount_finishes(self):
        self.spool.record_media_notification('connected', 'D:', 'usb', stamp())
        self.assertEqual(0, self.spool.record_media_state([], stamp()))
        self.assertEqual(['connected'], [e['kind'] for e in self.spool.pending_media_events()])
        self.spool.record_media_state([mounted()], stamp())
        self.assertEqual('Pendrive', self.spool.pending_media_events()[0]['volume']['label'])

    def test_fast_removal_is_not_held_indefinitely(self):
        self.spool.record_media_notification('connected', 'D:', 'usb', stamp(-45))
        self.spool.record_media_notification('disconnected', 'D:', 'usb', stamp())
        self.assertEqual(2, len(self.spool.pending_media_events(ready_only=True)))
        self.assertEqual(['connected', 'disconnected'],
                         [e['kind'] for e in self.spool.pending_media_events()])

    def test_latest_arrival_only_enriched_after_reinsert_same_letter(self):
        self.spool.record_media_notification('connected', 'D:', 'usb', stamp(-50))
        self.spool.record_media_notification('disconnected', 'D:', 'usb', stamp(-40))
        self.spool.record_media_notification('connected', 'D:', 'usb', stamp(-30))
        self.spool.record_media_state([mounted(serial='SECOND')], stamp())
        events = self.spool.pending_media_events()
        self.assertEqual(0, events[0]['volume']['total_bytes'])
        self.assertEqual(0, events[1]['volume']['total_bytes'])
        self.assertEqual('SECOND', events[2]['volume']['serial'])
        self.assertEqual('Pendrive', events[2]['volume']['label'])

    def test_size_unknown_still_delivers_after_grace_period(self):
        self.spool.record_media_notification('connected', 'E:', 'usb', stamp(-30))
        pending = self.spool.pending_media_events(ready_only=True)
        self.assertEqual(1, len(pending))
        self.assertEqual(0, pending[0]['volume']['total_bytes'])


if __name__ == '__main__':
    unittest.main()
