"""Transition rules. All API and SCM operations mocked; never change the host."""
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import nodo_agent as agent
import provisioning


class FakeLifecycle:
    def __init__(self, exists=True, stop_fails=False, start_fails=False):
        self._exists = exists
        self._running = exists
        self.stop_fails = stop_fails
        self.start_fails = start_fails
        self.events = []
    def exists(self): return self._exists
    def stop(self):
        self.events.append('stop')
        if self.stop_fails: raise OSError('cannot stop')
        self._running = False
    def install(self):
        self.events.append('install')
        self._exists = True
    def start(self):
        self.events.append('start')
        if self.start_fails: raise OSError('cannot start')
        self._running = True
    def running(self): return self._running


class BindingTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root = Path(self.tmp.name)
        self.patches = [patch.object(agent, 'CONFIG_DIR', root), patch.object(agent, 'CONFIG_FILE', root/'agent.json'), patch.object(agent, 'SPOOL_FILE', root/'telemetry.sqlite')]
        for p in self.patches:
            p.start()
            self.addCleanup(p.stop)
        agent.save_config({'uuid': 'old', 'server':'http://192.168.0.105:8001', 'token':'original-token', 'allow_http':True})
        (root / 'telemetry.sqlite').write_bytes(b'old-data-preserved')
        self.base = root

    @staticmethod
    def good_response(server, endpoint, method, **kwargs):
        return {'device_id': kwargs['payload']['uuid'], 'token': 'a'*64}

    def test_bad_code_never_touches_running_service_or_credentials(self):
        service = FakeLifecycle()
        with self.assertRaisesRegex(ValueError, '48 caracteres'):
            provisioning.link_device('192.168.0.111', 8001, 'BAD', lifecycle=service)
        self.assertEqual(service.events, [])
        self.assertEqual(agent.load_config()['uuid'], 'old')

    def test_server_422_preserves_old_identity_and_service(self):
        service = FakeLifecycle()
        with patch.object(agent, 'call_api', side_effect=agent.ApiError(422)):
            with self.assertRaisesRegex(ValueError, 'inválida'):
                provisioning.link_device('192.168.0.111', 8001, 'b'*48, lifecycle=service)
        self.assertEqual(service.events, [])
        self.assertEqual(agent.load_config()['server'], 'http://192.168.0.105:8001')

    def test_success_changes_only_after_validation_preserves_old_spool(self):
        service = FakeLifecycle()
        with patch.object(agent, 'call_api', side_effect=self.good_response):
            result = provisioning.link_device('192.168.0.111', '8001', 'b'*48, lifecycle=service)
        self.assertTrue(result.replaced)
        self.assertEqual(service.events, ['stop', 'install', 'start'])
        self.assertEqual(agent.load_config()['server'], 'http://192.168.0.111:8001')
        self.assertNotEqual(agent.spool_path_for(agent.load_config()), self.base / 'telemetry.sqlite')
        self.assertEqual((self.base / 'telemetry.sqlite').read_bytes(), b'old-data-preserved')
        self.assertEqual(agent.load_config()['token'], 'a'*64)

    def test_failure_to_stop_preserves_old_config(self):
        service = FakeLifecycle(stop_fails=True)
        with patch.object(agent, 'call_api', side_effect=self.good_response):
            with self.assertRaises(OSError):
                provisioning.link_device('192.168.0.111', 8001, 'b'*48, lifecycle=service)
        self.assertEqual(agent.load_config()['uuid'], 'old')
        self.assertEqual(service.events, ['stop'])

    def test_failed_start_rolls_back_old_identity(self):
        service = FakeLifecycle(start_fails=True)
        with patch.object(agent, 'call_api', side_effect=self.good_response):
            with self.assertRaisesRegex(RuntimeError, 'restauró'):
                provisioning.link_device('192.168.0.111', 8001, 'b'*48, lifecycle=service)
        self.assertEqual(agent.load_config()['uuid'], 'old')
        self.assertEqual(service.events, ['stop','install','start','start'])

    def test_unreachable_server_does_not_touch_existing_binding(self):
        service = FakeLifecycle()
        with patch.object(agent, 'call_api', side_effect=OSError('offline')):
            with self.assertRaises(OSError):
                provisioning.link_device('192.168.0.111', 8001, 'b'*48, lifecycle=service)
        self.assertEqual(service.events, [])
        self.assertEqual(agent.load_config()['token'], 'original-token')

    def test_first_setup_and_repair_can_resume_without_new_code(self):
        agent.CONFIG_FILE.unlink()
        service = FakeLifecycle(exists=False)
        with patch.object(agent, 'call_api', side_effect=self.good_response):
            result = provisioning.link_device('192.168.0.111', 8001, 'b'*48, lifecycle=service)
        self.assertFalse(result.replaced)
        self.assertEqual(service.events, ['install','start'])
        self.assertTrue(provisioning.retry_service(lifecycle=service))

    def test_input_requires_private_ip_and_valid_port(self):
        for ip,port in [('1.1.1.1','8001'), ('127.0.0.1','8001'), ('192.168.0.111', '99999'), ('8.8.8.8', 'abc')]:
            with self.subTest(ip=ip, port=port), self.assertRaises(ValueError):
                provisioning.make_server(ip, port)
        self.assertEqual(provisioning.make_server('192.168.0.111', 8001), 'http://192.168.0.111:8001')

    def test_untrusted_server_response_does_not_modify_old_identity(self):
        with patch.object(agent, 'call_api', return_value={'device_id':'wrong','token':'a'*64}):
            with self.assertRaisesRegex(RuntimeError, 'Respuesta'):
                provisioning.link_device('192.168.0.111', 8001, 'b'*48)
        self.assertEqual(agent.load_config()['uuid'], 'old')

    def test_spool_filename_path_traversal_is_rejected(self):
        with self.assertRaises(ValueError):
            agent.spool_path_for({'spool_file':'../../secret.txt'})

if __name__ == '__main__':
    unittest.main()

class ProcessTerminationTests(unittest.TestCase):
    """Process termination is authorized by the admin endpoint, not a SID hint.

    Only fake psutil processes are used: tests never terminate host processes.
    """

    @staticmethod
    def process(*, name='notepad.exe', started=1700000000.4):
        from unittest.mock import Mock
        proc = Mock()
        proc.name.return_value = name
        proc.create_time.return_value = started
        return proc

    def test_regular_process_is_terminated_even_if_session_check_is_unavailable(self):
        from unittest.mock import patch
        proc = self.process()
        with patch.object(agent.psutil, 'Process', return_value=proc):
            self.assertIn('finalizado', agent.terminate_process({
                'pid': 823, 'process_name': 'notepad.exe', 'process_started_at': 1700000000.4,
            }))
        proc.terminate.assert_called_once_with()
        proc.wait.assert_called_once_with(timeout=5)

    def test_process_from_other_user_is_not_preemptively_rejected(self):
        from unittest.mock import patch
        proc = self.process()
        # No owner or session lookup is required. Windows checks access.
        proc.username.side_effect = OSError('Session not accessible')
        with patch.object(agent.psutil, 'Process', return_value=proc):
            agent.terminate_process({
                'pid': 823, 'process_name': 'notepad.exe', 'process_started_at': 1700000000.4,
            })
        proc.terminate.assert_called_once()
        proc.username.assert_not_called()

    def test_windows_access_denied_is_reported_not_faked_as_success(self):
        from unittest.mock import patch
        proc = self.process()
        proc.terminate.side_effect = agent.psutil.AccessDenied(pid=823)
        with patch.object(agent.psutil, 'Process', return_value=proc):
            result, _message = agent.execute_command({'action': 'terminate_process', 'parameters': {
                'pid': 823, 'process_name': 'notepad.exe', 'process_started_at': 1700000000.4,
            }})
        self.assertEqual('failed', result)
        proc.wait.assert_not_called()

    def test_reused_pid_is_rejected_before_terminate(self):
        from unittest.mock import patch
        proc = self.process(started=1700000100.0)
        with patch.object(agent.psutil, 'Process', return_value=proc):
            with self.assertRaisesRegex(ValueError, 'no coincide'):
                agent.terminate_process({
                    'pid': 823, 'process_name': 'notepad.exe', 'process_started_at': 1700000000.4,
                })
        proc.terminate.assert_not_called()

    def test_name_mismatch_is_rejected(self):
        from unittest.mock import patch
        proc = self.process(name='other.exe')
        with patch.object(agent.psutil, 'Process', return_value=proc):
            with self.assertRaises(ValueError):
                agent.terminate_process({
                    'pid': 823, 'process_name': 'notepad.exe', 'process_started_at': 1700000000.4,
                })
        proc.terminate.assert_not_called()

    def test_protected_process_is_rejected_before_opening_pid(self):
        from unittest.mock import patch
        with patch.object(agent.psutil, 'Process') as ctor:
            with self.assertRaises(PermissionError):
                agent.terminate_process({'pid': 823, 'process_name': 'lsass.exe', 'process_started_at': 1700000000.4})
        ctor.assert_not_called()

    def test_invalid_started_at_is_rejected(self):
        from unittest.mock import patch
        with patch.object(agent.psutil, 'Process') as ctor:
            with self.assertRaises(ValueError):
                agent.terminate_process({'pid': 823, 'process_name': 'notepad.exe', 'process_started_at': True})
        ctor.assert_not_called()

class EnrollmentFailureTests(unittest.TestCase):
    def test_refuse_untrusted_success_without_token(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            with patch.object(agent, 'CONFIG_DIR', root), patch.object(agent, 'CONFIG_FILE', root / 'agent.json'), \
                 patch.object(agent, 'call_api', return_value={'device_id': 'anything'}):
                with self.assertRaisesRegex(RuntimeError, 'Respuesta de vinculación inválida'):
                    provisioning.link_device('192.168.0.111', '8001', 'a'*48)
                self.assertFalse((root / 'agent.json').exists())

    def test_ip_and_port_must_be_explicit_and_private_even_with_https(self):
        for address in ('198.51.100.1', '224.0.0.1', '0.0.0.0', '169.254.3.2'):
            with self.subTest(address=address), self.assertRaises(ValueError):
                provisioning.make_server(address, 443, scheme='https')
        self.assertEqual(provisioning.make_server('10.0.0.5', '443', scheme='https'),
                         'https://10.0.0.5:443')
