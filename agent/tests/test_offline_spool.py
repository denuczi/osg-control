"""Integration-style tests of the durable outbox, no remote actions executed."""
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from spool import LocalSpool
import nodo_agent as agent

SAMPLE = {"hostname": "PC-LAB", "platform": "Windows 11", "cpu_percent": 25,
          "memory_percent": 61, "disk_percent": 35, "memory_total_bytes": 8_589_934_592,
          "processes": []}


class SpoolTests(unittest.TestCase):
    def test_persists_after_restart_and_acks_only_confirmed_samples(self):
        with tempfile.TemporaryDirectory() as td:
            path = Path(td) / "telemetry.sqlite"
            a = LocalSpool(path)
            first = a.add_sample(SAMPLE)
            second = a.add_sample(SAMPLE)
            del a  # simulate stop/reboot
            b = LocalSpool(path)
            self.assertEqual(2, b.sample_count())
            self.assertEqual([first, second], [x['sample_uuid'] for x in b.pending_samples()])
            with patch.object(agent, 'call_api', side_effect=OSError('LAN caída')):
                with self.assertRaises(OSError):
                    agent.sync_samples(b, 'https://test.local', 'token')
            self.assertEqual(2, b.sample_count())
            with patch.object(agent, 'call_api', return_value={'accepted_ids': [first]}):
                agent.sync_samples(b, 'https://test.local', 'token')
            self.assertEqual([second], [x['sample_uuid'] for x in b.pending_samples()])
            with patch.object(agent, 'call_api', return_value={'accepted_ids': [second]}):
                agent.sync_samples(b, 'https://test.local', 'token')
            self.assertEqual(0, LocalSpool(path).sample_count())

    def test_five_second_capture_is_accepted_and_upload_cadence_follows(self):
        with tempfile.TemporaryDirectory() as td:
            cfg = {"uuid": "test", "settings": dict(agent.DEFAULT_SETTINGS)}
            with patch.object(agent, "CONFIG_DIR", Path(td)), \
                 patch.object(agent, "CONFIG_FILE", Path(td) / "config.json"):
                effective = agent.update_settings(cfg, {"settings": {"metrics_interval_seconds": 5}})
                self.assertEqual(effective["metrics_interval_seconds"], 5)
                self.assertEqual(agent.metrics_sync_interval(effective), 5)
                self.assertEqual(agent.metrics_sync_interval({"metrics_interval_seconds": 10}), 10)
                self.assertEqual(agent.metrics_sync_interval({"metrics_interval_seconds": 60}), 15)
                self.assertEqual(agent.metrics_sync_interval({"metrics_interval_seconds": 1}), 5)
                self.assertEqual(agent.metrics_sync_interval({"metrics_interval_seconds": True}), 15)
                self.assertEqual(agent.load_config()["settings"]["metrics_interval_seconds"], 5)

    def test_missing_or_invalid_ack_never_drops_local_data(self):
        with tempfile.TemporaryDirectory() as td:
            b = LocalSpool(Path(td) / 'db.sqlite')
            b.add_sample(SAMPLE)
            with patch.object(agent, 'call_api', return_value={'accepted_ids': ['wrong-id']}):
                with self.assertRaises(RuntimeError):
                    agent.sync_samples(b, 'https://test.local', 'token')
            self.assertEqual(b.sample_count(), 1)

    def test_command_not_reexecuted_after_agent_reboot(self):
        with tempfile.TemporaryDirectory() as td:
            path = Path(td) / 'db.sqlite'
            a = LocalSpool(path)
            self.assertTrue(a.begin_command('cmd-001'))
            del a
            b = LocalSpool(path)
            self.assertFalse(b.begin_command('cmd-001'))
            self.assertEqual('unknown', b.pending_results()[0]['result']['status'])
            b.finish_command('cmd-001', 'completed', 'acción realizada')
            b.acknowledge_result('cmd-001')
            self.assertEqual([], LocalSpool(path).pending_results())

    def test_settings_preserved_and_invalid_server_data_ignored(self):
        with tempfile.TemporaryDirectory() as td:
            cfg = {'uuid': 'abc', 'settings': {'metrics_interval_seconds': 120,
                   'heartbeat_interval_seconds': 30, 'command_poll_seconds': 10}}
            with patch.object(agent, 'CONFIG_DIR', Path(td)), patch.object(agent, 'CONFIG_FILE', Path(td)/'cfg.json'):
                effective = agent.update_settings(cfg, {'settings': {
                    'metrics_interval_seconds': 3, 'heartbeat_interval_seconds': 45,
                    'command_poll_seconds': '1'}})
                self.assertEqual(effective['metrics_interval_seconds'], 120)
                self.assertEqual(effective['heartbeat_interval_seconds'], 45)
                self.assertEqual(effective['command_poll_seconds'], 10)
                self.assertEqual(agent.load_config()['settings']['heartbeat_interval_seconds'], 45)

    def test_agent_keeps_collecting_offline_after_restart_and_drains_queue(self):
        import threading
        with tempfile.TemporaryDirectory() as td:
            directory = Path(td)
            config_path = directory / 'agent.json'
            spool_path = directory / 'telemetry.sqlite'
            with patch.object(agent, 'CONFIG_DIR', directory), \
                 patch.object(agent, 'CONFIG_FILE', config_path), \
                 patch.object(agent, 'SPOOL_FILE', spool_path), \
                 patch.object(agent, 'snapshot', return_value=SAMPLE):
                agent.save_config({'uuid': 'lab-1', 'server': 'https://server.test', 'token': 'secret'})
                with patch.object(agent, 'call_api', side_effect=OSError('servidor inaccesible')):
                    with self.assertRaisesRegex(RuntimeError, 'Diagnóstico con fallos'):
                        agent.run_agent(threading.Event(), once=True)
                    with self.assertRaisesRegex(RuntimeError, 'Diagnóstico con fallos'):
                        agent.run_agent(threading.Event(), once=True)
                self.assertEqual(LocalSpool(spool_path).sample_count(), 2)

                def online_request(server, endpoint, method, **kwargs):
                    if endpoint.endswith('/metrics/batch'):
                        return {'accepted_ids': [x['sample_uuid'] for x in kwargs['payload']['samples']]}
                    if endpoint.endswith('/network-usage/batch'):
                        return {'accepted': [{'bucket_uuid': b['bucket_uuid'], 'revision': b['revision']}
                                            for b in kwargs['payload']['buckets']]}
                    if endpoint.endswith('/commands/next'):
                        return {'command': None}
                    return {'ok': True, 'settings': agent.DEFAULT_SETTINGS}

                with patch.object(agent, 'call_api', side_effect=online_request):
                    agent.run_agent(threading.Event(), once=True)
                self.assertEqual(LocalSpool(spool_path).sample_count(), 0)

    def test_batch_is_limited_in_size(self):
        with tempfile.TemporaryDirectory() as td:
            db = LocalSpool(Path(td)/'telemetry.sqlite')
            for _ in range(5):
                db.add_sample(SAMPLE)
            self.assertEqual(2, len(db.pending_samples(limit=2)))
            self.assertLessEqual(len(db.pending_samples(max_bytes=1000)), 5)


if __name__ == '__main__':
    unittest.main()
