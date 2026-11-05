"""Version/ESU metadata is optional and must never fabricate license coverage."""
import sys
import unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import diagnostics_normalize as normalize
import diagnostics_v6


class WindowsLifecycleAgentTests(unittest.TestCase):
    def test_normalize_os_and_commercial_esu_without_keys(self):
        raw = {'DefenderRealtime': True, 'Windows': {
            'Caption': 'Microsoft Windows 10 Pro', 'EditionId': 'Professional',
            'DisplayVersion': '22H2', 'Build': 19045, 'UBR': 7820,
            'EsuCommercialStatus': 'licensed', 'EsuLicensedYears': [2],
            'ProductKey': 'NOT_ALLOWED',
        }}
        result = normalize.security(raw)
        self.assertEqual(19045, result['Windows']['Build'])
        self.assertEqual([2], result['Windows']['EsuLicensedYears'])
        self.assertNotIn('ProductKey', result['Windows'])

    def test_license_absence_never_means_no_esu(self):
        result = normalize.security({'Windows': {
            'Caption': 'Windows 10', 'EditionId': 'Professional',
            'Build': 19045, 'EsuCommercialStatus': 'not_detected',
            'EsuLicensedYears': []
        }})
        self.assertEqual('not_detected', result['Windows']['EsuCommercialStatus'])
        self.assertEqual([], result['Windows']['EsuLicensedYears'])
        self.assertIsNone(result['Windows']['UBR'])

    def test_bad_metadata_stays_unknown_and_does_not_break_defender(self):
        result = normalize.security({'DefenderRealtime': True, 'Windows': {
            'Build': 'oops', 'UBR': -1, 'EsuCommercialStatus': 'not_installed',
            'EsuLicensedYears': [0, 2, 5]
        }})
        self.assertIsNone(result['Windows']['Build'])
        self.assertIsNone(result['Windows']['UBR'])
        self.assertEqual('unknown', result['Windows']['EsuCommercialStatus'])
        self.assertEqual([2], result['Windows']['EsuLicensedYears'])
        self.assertTrue(result['DefenderRealtime'])

    def test_unavailable_license_query_keeps_windows_version(self):
        with patch.object(diagnostics_v6, '_windows_query', side_effect=[
            {'Caption': 'Microsoft Windows 10 Pro', 'EditionId':'Professional', 'Build':19045}, None
        ]):
            result = diagnostics_v6.windows_version()
        self.assertEqual(19045, result['Build'])
        self.assertEqual('unknown', result['EsuCommercialStatus'])

    def test_security_collects_windows_without_blocking_existing_status(self):
        with patch.object(diagnostics_v6, '_windows_query', side_effect=[{'DefenderRealtime': True}, {'Caption':'Windows 10', 'Build':19045}, {'Status':'not_detected', 'Years':[]}]):
            result = diagnostics_v6.security()
        self.assertEqual('Windows 10', result['Windows']['Caption'])
        self.assertTrue(result['DefenderRealtime'])


if __name__ == '__main__':
    unittest.main()
