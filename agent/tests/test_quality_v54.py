"""No router, no physical Windows peripherals: deterministic regression tests."""
import ctypes
import os
import tempfile
import time
import unittest
from datetime import datetime, timezone
from pathlib import Path
from unittest.mock import patch

import nodo_agent
from spool import LocalSpool
from windows_media_events import (
    BroadcastVolume, DBTF_MEDIA, DBT_DEVICEARRIVAL,
    DBT_DEVICEREMOVECOMPLETE, DBT_DEVTYP_VOLUME, event_volumes,
)


def at():
    return datetime.now(timezone.utc).isoformat(timespec='milliseconds')


class QualityV54Tests(unittest.TestCase):
    def test_fast_usb_insert_and_removal_are_durable_without_polling(self):
        with tempfile.TemporaryDirectory() as folder:
            db = LocalSpool(Path(folder) / 'events.sqlite')
            self.assertTrue(db.record_media_notification('connected', 'E:', 'usb', at()))
            self.assertTrue(db.record_media_notification('disconnected', 'E:', 'usb', at()))
            events = db.pending_media_events()
            self.assertEqual(['connected', 'disconnected'], [e['kind'] for e in events])
            self.assertEqual('E:', events[0]['volume']['device'])
            self.assertEqual(0, db.record_media_state([], at()))

    def test_os_event_timestamp_and_cd_drive_letter_are_parsed(self):
        item = BroadcastVolume()
        item.size = ctypes.sizeof(item)
        item.device_type = DBT_DEVTYP_VOLUME
        item.unitmask = (1 << 3) | (1 << 4)
        item.flags = DBTF_MEDIA
        raw = event_volumes(DBT_DEVICEARRIVAL, ctypes.addressof(item))
        self.assertEqual([('connected', 'D:', 'optical'), ('connected', 'E:', 'optical')], raw)
        self.assertEqual([('disconnected', 'D:', 'optical'), ('disconnected', 'E:', 'optical')],
                         event_volumes(DBT_DEVICEREMOVECOMPLETE, ctypes.addressof(item)))
        self.assertEqual([], event_volumes(0, ctypes.addressof(item)))

    def test_poll_enriches_placeholder_without_duplicate_insert(self):
        with tempfile.TemporaryDirectory() as folder:
            db = LocalSpool(Path(folder) / 'events.sqlite')
            db.record_media_notification('connected', 'F:', 'usb', at())
            actual = dict(device='F:', mountpoint='F:\\', media_class='usb', serial='001',
                          removable=True, total_bytes=100, used_bytes=5, free_bytes=95)
            self.assertEqual(0, db.record_media_state([actual], at()))
            self.assertEqual(['connected'], [e['kind'] for e in db.pending_media_events()])

    def test_pending_user_is_not_acknowledged_before_click_and_crash_is_unknown(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'outbox.sqlite'
            db = LocalSpool(path)
            db.begin_command('cmd-123')
            db.mark_awaiting_user('cmd-123')
            self.assertEqual([], db.pending_results())
            restarted = LocalSpool(path)
            self.assertEqual('unknown', restarted.pending_results()[0]['result']['status'])

    def test_dispatch_message_only_completes_after_waiter_result(self):
        with tempfile.TemporaryDirectory() as folder:
            db = LocalSpool(Path(folder) / 'outbox.sqlite')
            db.begin_command('cmd-456')
            with patch('windows_interactive.launch_message', return_value=[object()]), \
                 patch('windows_interactive.wait_for_close', return_value=('completed', 'Cerrado')):
                nodo_agent._dispatch_notification(db, 'cmd-456', {'message': 'Hola'})
                for i in range(100):
                    if db.pending_results():
                        break
                    time.sleep(.01)
            self.assertEqual('completed', db.pending_results()[0]['result']['status'])

    def test_notifier_launch_failure_never_claims_completed(self):
        with tempfile.TemporaryDirectory() as folder:
            db = LocalSpool(Path(folder) / 'outbox.sqlite')
            db.begin_command('cmd-789')
            with patch('windows_interactive.launch_message', side_effect=OSError('session unavailable')):
                nodo_agent._dispatch_notification(db, 'cmd-789', {'message':'Hola'})
            self.assertEqual('failed', db.pending_results()[0]['result']['status'])


if __name__ == '__main__':
    unittest.main()
