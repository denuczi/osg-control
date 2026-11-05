"""Regresiones auditadas: ninguna prueba modifica red, router o servicios reales."""
from __future__ import annotations

import io
import json
from contextlib import closing
import tempfile
import threading
import unittest
from pathlib import Path
from urllib.error import HTTPError
from unittest.mock import patch

import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import nodo_agent as agent
from spool import LocalSpool

SAMPLE = {"hostname": "PC", "platform": "Windows", "cpu_percent": 11,
          "memory_percent": 22, "disk_percent": 33, "memory_total_bytes": 8192}


class StabilityTests(unittest.TestCase):
    def test_http_422_logs_only_endpoint_and_field_names(self):
        payload = {"message": "token-secret", "errors": {
            "volumes.0.used_percent": ["valor-private-token-secret"]}}
        error = HTTPError("http://server/api", 422, "invalid", {}, io.BytesIO(json.dumps(payload).encode()))
        with patch.object(agent.request, "urlopen", side_effect=error):
            with self.assertRaises(agent.ApiError) as caught:
                agent.call_api("http://192.168.0.1", "/api/v1/agents/inventory", "POST", token="secret")
        msg = str(caught.exception)
        self.assertIn("/api/v1/agents/inventory", msg)
        self.assertIn("volumes.0.used_percent", msg)
        self.assertNotIn("token-secret", msg)
        self.assertNotIn("valor-private", msg)

    def test_rfc1918_allowed_but_reserved_and_public_not_allowed(self):
        for ip in ("0.0.0.0", "169.254.1.3", "100.64.0.1", "8.8.8.8", "224.0.0.1"):
            with self.subTest(ip=ip), self.assertRaises(ValueError):
                agent.validate_server(f"http://{ip}:8001", True)
        self.assertEqual(agent.validate_server("http://192.168.0.105:8001", True), "http://192.168.0.105:8001")

    def test_invalid_inventory_does_not_block_telemetry_or_results(self):
        with tempfile.TemporaryDirectory() as td:
            base = Path(td)
            with patch.object(agent, "CONFIG_DIR", base), patch.object(agent, "CONFIG_FILE", base / 'agent.json'), \
                 patch.object(agent, "SPOOL_FILE", base / 'db.sqlite'), \
                 patch.object(agent, "_capture_inventory"):
                agent.save_config({"uuid": "lab-1", "server": "http://192.168.0.105:8001",
                                   "token": "dont-print", "allow_http": True})
                db = LocalSpool(base / 'db.sqlite')
                db.save_inventory({"captured_at": "2026-10-29T12:00:00Z", "processor": {}, "volumes": [], "network": {}})
                db.add_sample(SAMPLE)
                calls = []

                def fake_call(server, endpoint, method="GET", **kwargs):
                    calls.append(endpoint)
                    if endpoint.endswith("/inventory"):
                        raise agent.ApiError(422, endpoint=endpoint, fields=("processor.max_mhz",))
                    if endpoint.endswith("/metrics/batch"):
                        return {"accepted_ids": [x['sample_uuid'] for x in kwargs['payload']['samples']]}
                    if endpoint.endswith("/commands/next"):
                        return {"command": None}
                    return {"settings": agent.DEFAULT_SETTINGS}

                with patch.object(agent, "call_api", side_effect=fake_call):
                    with self.assertRaisesRegex(RuntimeError, "inventory"):
                        agent.run_agent(threading.Event(), once=True)
                self.assertIn("/api/v1/agents/metrics/batch", calls)
                self.assertIn("/api/v1/agents/commands/next", calls)
                self.assertEqual(db.sample_count(), 0)
                self.assertIsNotNone(db.pending_inventory())

    def test_422_bisection_preserves_invalid_and_acks_valid_samples(self):
        with tempfile.TemporaryDirectory() as td:
            db = LocalSpool(Path(td) / 'spool.sqlite')
            good1 = db.add_sample(SAMPLE)
            broken = db.add_sample(SAMPLE)
            good2 = db.add_sample(SAMPLE)
            calls = []

            def fake_call(server, endpoint, method, **kwargs):
                ids = [v['sample_uuid'] for v in kwargs['payload']['samples']]
                calls.append(ids)
                if broken in ids:
                    raise agent.ApiError(422, endpoint=endpoint, fields=("samples.1.cpu_percent",))
                return {"accepted_ids": ids}

            with patch.object(agent, "call_api", side_effect=fake_call):
                with self.assertRaises(agent.ApiError):
                    agent.sync_samples(db, "https://example.test", "token")
            self.assertGreater(len(calls), 2)
            self.assertEqual(db.sample_count(), 1)
            self.assertEqual(db.pending_samples(), [])  # temporarily deferred, not deleted
            self.assertEqual(db.retry_deferred_samples(), 1)
            self.assertEqual([x['sample_uuid'] for x in db.pending_samples()], [broken])

    def test_usb_batch_with_invalid_event_preserves_only_rejected_record(self):
        with tempfile.TemporaryDirectory() as td:
            db = LocalSpool(Path(td) / 'db.sqlite')
            db.record_media_state([], '2026-10-29T09:00:00Z')
            usb1 = {'device': 'USB1', 'mountpoint': 'E:\\', 'removable': True,
                    'total_bytes': 1000, 'used_bytes': 100, 'free_bytes': 900}
            usb2 = {'device': 'USB2', 'mountpoint': 'F:\\', 'removable': True,
                    'total_bytes': 1000, 'used_bytes': 100, 'free_bytes': 900}
            db.record_media_state([usb1, usb2], '2026-10-29T10:00:00Z')
            events = db.pending_media_events()
            rejected = events[0]['event_uuid']

            def fake_call(server, endpoint, method, **kwargs):
                ids = [event['event_uuid'] for event in kwargs['payload']['events']]
                if rejected in ids:
                    raise agent.ApiError(422, endpoint=endpoint, fields=('events.0.volume.device',))
                return {'accepted_ids': ids}

            with patch.object(agent, 'call_api', side_effect=fake_call):
                with self.assertRaises(agent.ApiError):
                    agent._sync_events(db, 'http://192.168.0.105', 'token')
            self.assertEqual(db.pending_media_events(), [])
            self.assertEqual(db.retry_deferred_samples(), 1)
            self.assertEqual(db.pending_media_events()[0]['event_uuid'], rejected)

    def test_sqlite_schema_migrates_without_dropping_old_samples(self):
        import sqlite3
        with tempfile.TemporaryDirectory() as td:
            path = Path(td) / 'old.sqlite'
            with closing(sqlite3.connect(path)) as conn, conn:
                conn.execute("CREATE TABLE samples(sequence INTEGER PRIMARY KEY AUTOINCREMENT, sample_uuid TEXT UNIQUE NOT NULL, captured_at TEXT NOT NULL, payload TEXT NOT NULL)")
                conn.execute("INSERT INTO samples(sample_uuid,captured_at,payload) VALUES('abc','2026-10-29', '{}')")
            db = LocalSpool(path)
            self.assertEqual(db.sample_count(), 1)
            with closing(sqlite3.connect(path)) as conn, conn:
                self.assertIn('retry_after', [r[1] for r in conn.execute('PRAGMA table_info(samples)')])

    def test_read_denied_is_not_misreported_as_missing_credentials(self):
        with patch.object(agent.Path, 'read_text', side_effect=PermissionError('denied')):
            with self.assertRaisesRegex(RuntimeError, "Acceso denegado"):
                agent.load_config()

    def test_processes_can_be_omitted_from_lightweight_snapshot(self):
        data = agent.snapshot(include_processes=False)
        self.assertNotIn('processes', data)
        self.assertIn('network_tx_bps', data)

    def test_command_receipts_expire_only_after_acknowledgment(self):
        with tempfile.TemporaryDirectory() as td:
            db = LocalSpool(Path(td) / 'db.sqlite')
            db.begin_command('a')
            db.begin_command('b')
            with db._connection() as conn:
                conn.execute("UPDATE command_receipts SET created_at='2020-01-01T00:00:00+00:00'")
            db.acknowledge_result('a')
            db.purge_acked_commands(keep_days=90)
            self.assertFalse(db.has_command('a'))
            self.assertTrue(db.has_command('b'))


if __name__ == '__main__':
    unittest.main()
