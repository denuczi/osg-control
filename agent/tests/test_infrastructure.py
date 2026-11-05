"""Tests sin ejecutar comandos remotos, consultas WMI ni modificar discos reales."""
import tempfile
import unittest
from collections import namedtuple
from pathlib import Path
from unittest.mock import patch
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import inventory
import nodo_agent
from spool import LocalSpool

Counter = namedtuple('Counter', ['bytes_sent','bytes_recv'])


class InventoryTests(unittest.TestCase):
    def test_memory_and_disk_bytes_are_reported(self):
        data = nodo_agent.snapshot()
        for field in ('memory_used_bytes','memory_available_bytes','disk_total_bytes','disk_free_bytes'):
            self.assertIsInstance(data[field], int)
        self.assertGreaterEqual(data['disk_total_bytes'], data['disk_free_bytes'])

    def test_inventory_does_not_read_personal_files(self):
        data = inventory.collect_inventory()
        self.assertIn('volumes', data)
        self.assertIn('processor', data)
        self.assertIn('network', data)
        self.assertIn('interfaces', data['network'])

    def test_link_rates_use_counter_deltas_and_handle_resets(self):
        measurements = [
            {'Ethernet': Counter(100, 200)},
            {'Ethernet': Counter(500, 1200)},
            {'Ethernet': Counter(50, 30)},
        ]
        clocks = iter([10, 12, 14]); inputs = iter(measurements)
        status = type('Link', (), {'isup': True})()
        with patch.object(inventory.psutil, 'net_if_stats', return_value={'Ethernet': status}):
            meter = inventory.NetworkMeter(clock=lambda: next(clocks), counters=lambda: next(inputs))
            self.assertIsNone(meter.sample()['interfaces'][0]['rx_bps'])
            second = meter.sample()['interfaces'][0]
            self.assertEqual(4000, second['rx_bps'])
            self.assertEqual(1600, second['tx_bps'])
            self.assertIsNone(meter.sample()['interfaces'][0]['rx_bps'])

    def test_inventory_and_usb_events_survive_restart_and_ack(self):
        with tempfile.TemporaryDirectory() as td:
            db = Path(td) / 'agent.sqlite'
            a = LocalSpool(db)
            first = {'captured_at': '2026-10-29T10:00:00Z', 'volumes': [], 'processor': {}, 'network': {}}
            usb = {'device': r'\\.\PHYSICALDRIVE3', 'mountpoint': 'E:\\', 'total_bytes': 100,
                   'used_bytes': 20, 'free_bytes': 80, 'removable': True, 'label': 'USB'}
            self.assertEqual(0, a.record_media_state([], first['captured_at']))
            a.save_inventory(first)
            self.assertEqual(1, a.record_media_state([usb], '2026-10-29T10:01:00Z'))
            second = {**first, 'captured_at': '2026-10-29T10:01:00Z', 'volumes': [usb]}
            a.save_inventory(second)
            del a
            b = LocalSpool(db)
            self.assertEqual(second, b.pending_inventory())
            self.assertEqual(1, len(b.pending_media_events()))
            b.acknowledge_inventory(first['captured_at'])
            self.assertIsNotNone(b.pending_inventory())
            b.acknowledge_inventory(second['captured_at'])
            self.assertIsNone(b.pending_inventory())
            event_uuid = b.pending_media_events()[0]['event_uuid']
            b.acknowledge_media_events([event_uuid])
            self.assertEqual([], b.pending_media_events())
            self.assertEqual(1, b.record_media_state([], '2026-10-29T10:02:00Z'))
            self.assertEqual('disconnected', b.pending_media_events()[0]['kind'])

    def test_http_lan_flag_is_persisted_without_exposing_token(self):
        self.assertEqual('http://192.168.0.105:8001', nodo_agent.validate_server('http://192.168.0.105:8001', True))
        with self.assertRaises(ValueError):
            nodo_agent.validate_server('http://8.8.8.8:8001', True)


if __name__ == '__main__':
    unittest.main()
