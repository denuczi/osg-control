"""Regression tests for Windows-only diagnostics, without executing Windows commands."""
import json
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import inventory
import diagnostics_v6 as diagnostics
import diagnostics_normalize as normalize


class WindowsDiagnosticsQualityTests(unittest.TestCase):
    def test_powershell_output_is_explicitly_utf8(self):
        payload = {'Message': 'El proceso inició el reinicio; Código de motivo: Otros'}
        with patch.object(inventory.os, 'name', 'nt'), patch.object(inventory.subprocess, 'run') as run:
            run.return_value = SimpleNamespace(returncode=0, stdout=json.dumps(payload, ensure_ascii=False))
            self.assertEqual(payload, inventory._windows_query('ConvertTo-Json'))
            command = run.call_args.args[0]
            self.assertIn('[Console]::OutputEncoding', command[-1])
            self.assertIn('UTF8Encoding', command[-1])
            self.assertEqual('utf-8', run.call_args.kwargs['encoding'])
            self.assertEqual('strict', run.call_args.kwargs['errors'])

    def test_invalid_output_encoding_does_not_persist_replacement_characters(self):
        with patch.object(inventory.os, 'name', 'nt'), patch.object(inventory.subprocess, 'run',
             side_effect=UnicodeDecodeError('utf-8', b'\xff', 0, 1, 'invalid byte')):
            self.assertIsNone(inventory._windows_query('ConvertTo-Json'))

    def test_security_with_only_os_version_is_marked_unavailable(self):
        version = {'Build': 22621, 'Caption': 'Windows 11 Pro'}
        with patch.object(diagnostics, '_windows_query', side_effect=[None, version]):
            result = diagnostics.security()
        self.assertEqual('unavailable', result['ProtectionQueryStatus'])
        self.assertEqual(version, result['Windows'])

    def test_normal_shutdown_events_have_verified_providers(self):
        self.assertIn(13, diagnostics.EVENT_FILTERS['System'])
        self.assertIn(6006, diagnostics.EVENT_FILTERS['System'])
        self.assertIn('microsoft-windows-kernel-general', diagnostics.EVENT_PROVIDERS[13])
        self.assertIn('eventlog', diagnostics.EVENT_PROVIDERS[6006])

    def test_security_status_distinguishes_windows_identity_from_protection(self):
        version_only = normalize.security({'Windows': {'Caption': 'Windows 10 Pro', 'Build': 19045},
                                           'ProtectionQueryStatus': 'unavailable'})
        self.assertIsNotNone(version_only['Windows'])
        self.assertEqual('unavailable', version_only['ProtectionQueryStatus'])
        self.assertIsNone(version_only['DefenderMode'])
        full = normalize.security({'DefenderMode': 'Normal', 'ProtectionQueryStatus': 'ok'})
        self.assertEqual('ok', full['ProtectionQueryStatus'])


if __name__ == '__main__':
    unittest.main()
