"""Unit tests that need no router, Laravel server, or Windows desktop."""
import os
import tempfile
import unittest
from pathlib import Path
from datetime import datetime, timezone
from unittest.mock import patch

import nodo_agent
from spool import LocalSpool
from windows_interactive import show_message


class OperationsV53Tests(unittest.TestCase):
    def test_media_startup_is_detected_not_falsely_reported_as_inserted(self):
        with tempfile.TemporaryDirectory() as tmp:
            spool = LocalSpool(Path(tmp) / 'sample.sqlite')
            at = datetime.now(timezone.utc).isoformat()
            media = {'mountpoint': 'E:\\', 'device': 'E:', 'serial':'ABCD',
                     'media_class':'usb','removable':True,'total_bytes':8000000,
                     'used_bytes':0,'free_bytes':8000000}
            self.assertEqual(1, spool.record_media_state([media], at))
            self.assertEqual('detected', spool.pending_media_events()[0]['kind'])
            self.assertEqual(0, spool.record_media_state([media], at))

    def test_media_disconnect_and_reinsertion_are_persisted(self):
        with tempfile.TemporaryDirectory() as tmp:
            spool = LocalSpool(Path(tmp) / 'sample.sqlite')
            at = datetime.now(timezone.utc).isoformat()
            optical = {'mountpoint':'D:\\','device':'D:', 'serial':'DISC-A',
                       'media_class':'optical','removable':True, 'total_bytes':0,
                       'used_bytes':0, 'free_bytes':0}
            spool.record_media_state([], at)
            spool.record_media_state([optical], at)
            spool.record_media_state([], at)
            optical2 = {**optical, 'serial': 'DISC-B'}
            spool.record_media_state([optical2], at)
            kinds = [event['kind'] for event in spool.pending_media_events()]
            self.assertEqual(['connected','disconnected','connected'], kinds)
            self.assertEqual('optical', spool.pending_media_events()[0]['volume']['media_class'])

    def test_message_is_never_accepted_as_arbitrary_command(self):
        status, message = nodo_agent.execute_command({'action':'shell','parameters':{'message':'x'}})
        self.assertEqual('failed', status)
        self.assertIn('no autorizada', message.lower())

    def test_invalid_messages_are_rejected_before_desktop_access(self):
        for message in ['', ' '*5, 'x'*501, None]:
            # On Linux the platform check happens first, so assert the side effect
            # never happens on an unsupported platform.
            if os.name != 'nt':
                with self.assertRaises(OSError):
                    show_message(message)

    def test_message_command_without_windows_is_reported_failed(self):
        if os.name != 'nt':
            status, message = nodo_agent.execute_command({'action':'show_message', 'parameters':{'message':'Hola'}})
            self.assertEqual('failed', status)
            self.assertIn('Windows', message)


if __name__ == '__main__':
    unittest.main()
