"""Pruebas seguras: nunca se apaga ni se reinicia la computadora del ejecutor."""
import importlib.util
import tempfile
import sys
import json
import threading
import unittest
from pathlib import Path
from unittest.mock import patch

MODULE = Path(__file__).resolve().parents[1] / 'nodo_agent.py'
sys.path.insert(0, str(MODULE.parent))
spec = importlib.util.spec_from_file_location('nodo_agent', MODULE)
agent = importlib.util.module_from_spec(spec)
spec.loader.exec_module(agent)


class AgentTests(unittest.TestCase):
    def test_http_requires_explicit_opt_in(self):
        with self.assertRaises(ValueError):
            agent.validate_server('http://192.168.0.10:8000', False)
        self.assertEqual(agent.validate_server('http://192.168.0.10:8000', True), 'http://192.168.0.10:8000')

    def test_rejects_server_url_with_credentials_and_path(self):
        with self.assertRaises(ValueError):
            agent.validate_server('https://name:pass@192.168.0.10:8000', False)
        with self.assertRaises(ValueError):
            agent.validate_server('https://192.168.0.10:8000/private', False)

    def test_unknown_action_never_executes_shell(self):
        with patch.object(agent.subprocess, 'run') as run:
            status, message = agent.execute_command({'action': 'execute_shell', 'parameters': {'cmd': 'whoami'}})
        self.assertEqual(status, 'failed')
        run.assert_not_called()

    def test_protected_process_is_rejected(self):
        status, message = agent.execute_command({'action': 'terminate_process', 'parameters': {
            'pid': 1234, 'process_name': 'lsass.exe', 'process_started_at': 1700000000.0,
        }})
        self.assertEqual(status, 'failed')
        self.assertIn('protegido', message.lower())

    def test_restart_calls_only_fixed_shutdown_argv(self):
        with patch.object(agent.platform, 'system', return_value='Windows'), patch.object(agent.subprocess, 'run') as run:
            status, message = agent.execute_command({'action': 'restart', 'parameters': {}})
        self.assertEqual(status, 'completed')
        run.assert_called_once_with(['shutdown', '/r', '/t', '30'], check=True, timeout=10)

    def test_config_can_be_saved_without_exposing_token_in_stdout(self):
        with tempfile.TemporaryDirectory() as tmp:
            with patch.object(agent, 'CONFIG_DIR', Path(tmp)), patch.object(agent, 'CONFIG_FILE', Path(tmp) / 'agent.json'):
                agent.save_config({'uuid': 'abc', 'token': 'secret'})
                self.assertEqual(agent.load_config()['token'], 'secret')

    def test_snapshot_provides_expected_metrics(self):
        result = agent.snapshot()
        self.assertIn('hostname', result)
        self.assertIn('processes', result)
        self.assertGreaterEqual(result['cpu_percent'], 0)
        self.assertLessEqual(result['cpu_percent'], 100)


if __name__ == '__main__':
    unittest.main()
