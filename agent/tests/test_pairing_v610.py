"""Pairing authorization tests. No HTTP requests or Windows service changes."""
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import nodo_agent as agent
import provisioning
from test_provisioning import FakeLifecycle


class PairingV610Tests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        root = Path(self.temp.name)
        for attribute, value in [('CONFIG_DIR', root), ('CONFIG_FILE', root / 'agent.json')]:
            replacement = patch.object(agent, attribute, value)
            replacement.start()
            self.addCleanup(replacement.stop)
        agent.save_config({'uuid': 'old', 'server': 'http://192.168.0.20:8001',
                           'token': 'previous-secret', 'allow_http': True})

    def test_new_pairing_uses_random_secret_and_short_visual_code(self):
        request_id = '0d9c48e3-2932-4508-9a7f-d226df0847a2'
        with patch.object(agent, 'call_api', return_value={
            'request_id': request_id, 'verification_code': '004217',
            'expires_at': '2026-11-03T15:30:00Z',
        }) as api:
            code, pending = provisioning.request_pairing('192.168.0.15', 8001)
        self.assertEqual(code, '004217')
        self.assertEqual(pending['request_id'], request_id)
        self.assertEqual(len(pending['token']), 64)
        self.assertNotEqual(pending['token'], 'previous-secret')
        self.assertEqual(api.call_args.args[1], '/api/v1/agents/pairing')
        self.assertEqual(agent.load_config()['uuid'], 'old')

    def test_approval_changes_identity_only_after_verified_poll(self):
        code, pending = '133742', {
            'server': 'http://192.168.0.15:8001',
            'uuid': 'da439a6a-427f-4c7f-8800-fd5225a3ceaf',
            'token': 'b'*64, 'request_id': '73599752-a40c-40de-8fd7-f89474389a15',
            'allow_http': True, 'spool_file': 'telemetry-da439a6a-427f-4c7f-8800-fd5225a3ceaf.sqlite',
        }
        responses = [{'status': 'pending'}, {'status': 'approved', 'device_id': pending['uuid']}]
        service = FakeLifecycle()
        with patch.object(agent, 'call_api', side_effect=responses) as api, \
                patch.object(provisioning.time, 'sleep'):
            result = provisioning.await_pairing(pending, timeout_seconds=20, interval_seconds=.1,
                                               lifecycle=service)
        self.assertEqual(api.call_count, 2)
        self.assertEqual(api.call_args.kwargs['token'], 'b'*64)
        self.assertEqual(service.events, ['stop', 'install', 'start'])
        self.assertEqual(agent.load_config()['uuid'], pending['uuid'])
        self.assertTrue(result.service_running)

    def test_rejection_and_unexpected_identity_preserve_config(self):
        pending = {
            'server': 'http://192.168.0.15:8001',
            'uuid': 'da439a6a-427f-4c7f-8800-fd5225a3ceaf',
            'token': 'b'*64, 'request_id': '73599752-a40c-40de-8fd7-f89474389a15',
            'allow_http': True, 'spool_file': 'telemetry-da439a6a-427f-4c7f-8800-fd5225a3ceaf.sqlite',
        }
        for result in [{'status': 'rejected'}, {'status': 'approved', 'device_id': 'different'}]:
            with self.subTest(result=result), patch.object(agent, 'call_api', return_value=result):
                with self.assertRaises((ValueError, RuntimeError)):
                    provisioning.await_pairing(pending, timeout_seconds=1, interval_seconds=.1)
                self.assertEqual(agent.load_config()['uuid'], 'old')


if __name__ == '__main__':
    unittest.main()
