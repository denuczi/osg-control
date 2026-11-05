"""Regression tests: no Windows queries or remote process actions are executed."""
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import diagnostics_v6 as diagnostics
import diagnostics_normalize as normalize
import nodo_agent
from spool import LocalSpool


class DiagnosticTests(unittest.TestCase):
    def test_neighbor_filter_excludes_multicast_and_invalid_mac(self):
        self.assertTrue(diagnostics.valid_neighbor('192.168.0.5', '10-11-12-13-14-15'))
        self.assertFalse(diagnostics.valid_neighbor('239.255.255.250', '10-11-12-13-14-15'))
        self.assertFalse(diagnostics.valid_neighbor('255.255.255.255', '10-11-12-13-14-15'))
        self.assertFalse(diagnostics.valid_neighbor('192.168.0.5', '01-11-12-13-14-15'))
        self.assertFalse(diagnostics.valid_neighbor('192.168.0.5', 'FF-FF-FF-FF-FF-FF'))

    def test_explicit_unavailable_and_errors_do_not_invent_healthy_state(self):
        with patch.object(diagnostics, 'os', SimpleNamespace(name='nt')):
            self.assertEqual('unavailable', diagnostics._section(lambda: None)['status'])
            self.assertEqual('error', diagnostics._section(lambda: (_ for _ in ()).throw(ValueError()))['status'])
            self.assertEqual({'status': 'ok', 'data': {'A': 1}},
                             diagnostics._section(lambda: {'A': 1}))

    def test_unreliable_sensor_values_are_null_not_rejected(self):
        result = normalize.hardware({'Manufacturer':'Lenovo','Model':'Notebook',
                                     'BatteryPercent':255,'BatteryCycles':-1,
                                     'MemorySlots':2,
                                     'MemoryModules':[{'CapacityBytes':4294967296,
                                                       'SpeedMHz':99999,'Slot':'DIMM0'}]})
        self.assertIsNone(result['BatteryPercent'])
        self.assertIsNone(result['BatteryCycles'])
        self.assertIsNone(result['MemoryModules'][0]['SpeedMHz'])
        self.assertEqual(2, result['MemorySlots'])
        self.assertEqual(4294967296, result['MemoryModules'][0]['CapacityBytes'])

    def test_unavailable_security_does_not_report_no_antivirus(self):
        self.assertIsNone(normalize.security({'DefenderMode':None,
                     'DefenderRealtime':None,'AntivirusProviders':[], 'FirewallProfiles':[]}))
        result = normalize.security({'DefenderMode':'Passive Mode',
                     'DefenderRealtime':False,'AntivirusProviders':['Other AV'],
                     'FirewallProfiles':[{'Name':'Private','Enabled':True}]})
        self.assertEqual(['Other AV'], result['AntivirusProviders'])
        self.assertFalse(result['DefenderRealtime'])

    def test_invalid_temperature_and_wear_are_not_zero(self):
        result = normalize.disks({'drives':[{'Name':'SSD','Temperature':255,'Wear':255,
                                             'PowerOnHours':112}]})
        self.assertIsNone(result['drives'][0]['Temperature'])
        self.assertIsNone(result['drives'][0]['Wear'])
        self.assertEqual(112, result['drives'][0]['PowerOnHours'])

    def test_snapshot_ack_preserves_newer_capture_and_restart(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp)/'spool.db'
            a = LocalSpool(path)
            one = {'captured_at': '2026-11-02T10:00:00+00:00',
                   'hardware': {'status': 'ok', 'data': {}},
                   'disks': {'status': 'unavailable', 'data': None},
                   'security': {'status': 'error', 'data': None}}
            a.save_diagnostics(one)
            two = {**one, 'captured_at': '2026-11-02T10:30:00+00:00'}
            a.save_diagnostics(two)
            a.acknowledge_diagnostics(one['captured_at'])
            self.assertEqual(two, LocalSpool(path).pending_diagnostics())
            a.acknowledge_diagnostics(two['captured_at'])
            self.assertIsNone(a.pending_diagnostics())

    def test_event_queue_dedup_survives_ack_and_restart(self):
        example = {'channel':'System', 'record_id':22, 'event_id':41, 'level':2,
                   'provider':'Microsoft-Windows-Kernel-Power',
                   'observed_at':'2026-11-02T10:01:00+00:00'}
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp)/'spool.db'
            a = LocalSpool(path)
            self.assertEqual(1, a.record_system_events([example]))
            self.assertEqual(0, a.record_system_events([example]))
            first = a.pending_system_events()
            self.assertEqual(1, len(first))
            self.assertEqual(first, LocalSpool(path).pending_system_events())
            a.acknowledge_system_events([first[0]['event_uuid']])
            self.assertEqual(0, a.record_system_events([example]))
            self.assertEqual([], a.pending_system_events())

    def test_bad_ack_does_not_drop_events(self):
        event = {'channel':'System','record_id':15,'event_id':6008,'level':2,
                 'provider':'EventLog','observed_at':'2026-11-02T12:00:00+00:00'}
        with tempfile.TemporaryDirectory() as tmp:
            spool = LocalSpool(Path(tmp)/'spool.sqlite')
            spool.record_system_events([event])
            with patch.object(nodo_agent, 'call_api', return_value={'accepted_ids': []}):
                with self.assertRaises(RuntimeError):
                    nodo_agent._sync_system_events(spool, 'http://127.0.0.1:8001', 'token')
            self.assertEqual(1, len(spool.pending_system_events()))
            expected = spool.pending_system_events()[0]['event_uuid']
            with patch.object(nodo_agent, 'call_api', return_value={'accepted_ids': [expected]}):
                nodo_agent._sync_system_events(spool, 'http://127.0.0.1:8001', 'token')
            self.assertEqual([], spool.pending_system_events())

    def test_failed_snapshot_ack_keeps_data(self):
        with tempfile.TemporaryDirectory() as tmp:
            spool = LocalSpool(Path(tmp)/'spool.db')
            data = {'captured_at': '2026-11-02T10:00:00+00:00'}
            spool.save_diagnostics(data)
            with patch.object(nodo_agent, 'call_api', return_value={'ok': True, 'captured_at':'incorrect'}):
                with self.assertRaises(RuntimeError):
                    nodo_agent._sync_diagnostics(spool, 'http://127.0.0.1:8001', 'token')
            self.assertEqual(data, spool.pending_diagnostics())


if __name__ == '__main__':
    unittest.main()
