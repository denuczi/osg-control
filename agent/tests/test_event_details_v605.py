"""v6.0.5 event detail regression tests. No Windows device required."""
from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import diagnostics_v6
from spool import LocalSpool


class EventDetailTests(unittest.TestCase):
    def test_windows_message_and_sid_are_preserved_with_bounded_size(self):
        fake = {'Events': [
            {'RecordId': 111, 'EventId': 1074, 'Provider': 'User32', 'Channel': 'System',
             'Time': '2026-11-03T08:00:00+00:00', 'Level': 4,
             'Message': 'Usuario DOMINIO\\operador reinició C:\\Windows\\System32\\shutdown.exe',
             'UserSid': 'S-1-5-21-100'},
        ], 'LatestRecordId': 111}
        with patch.object(diagnostics_v6.os, 'name', 'nt'), patch.object(diagnostics_v6, '_windows_query', return_value=fake):
            received = diagnostics_v6.system_events({'System': 100}).events
        self.assertEqual(1, len(received))
        self.assertIn('shutdown.exe', received[0]['message'])
        self.assertEqual('S-1-5-21-100', received[0]['user_sid'])
        with tempfile.TemporaryDirectory() as tmp:
            spool = LocalSpool(Path(tmp) / 'events.sqlite')
            spool.record_system_events(received)
            self.assertIn('shutdown.exe', spool.pending_system_events()[0]['message'])

    def test_gigantic_multibyte_message_is_limited_to_1200_utf8_bytes(self):
        fake = {'Events': [
            {'RecordId': 200, 'EventId': 153, 'Provider': 'disk', 'Channel': 'System',
             'Time': '2026-11-03T08:00:00+00:00', 'Level': 3,
             'Message': 'á' * 1500, 'UserSid': 'invalid'},
        ], 'LatestRecordId': 200}
        with patch.object(diagnostics_v6.os, 'name', 'nt'), patch.object(diagnostics_v6, '_windows_query', return_value=fake):
            received = diagnostics_v6.system_events({'System': 100}).events
        self.assertLessEqual(len(received[0]['message'].encode('utf-8')), 1200)
        self.assertIsNone(received[0]['user_sid'])

if __name__ == '__main__':
    unittest.main()
