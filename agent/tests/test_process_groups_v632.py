"""Capture boundaries and grouping semantics; no Windows APIs or commands required."""
import sys
import unittest
from pathlib import Path
from unittest.mock import patch
from types import SimpleNamespace

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import psutil
import process_inventory as pi


class FakeProcess:
    def __init__(self, pid, name, mb, started, exe):
        self.info = {"pid": pid, "name": name, "memory_info": SimpleNamespace(rss=mb * 1048576),
                     "create_time": started}
        self.path = exe

    def exe(self):
        if self.path is None:
            raise psutil.AccessDenied(self.info['pid'])
        return self.path


class FakePsutil:
    AccessDenied = psutil.AccessDenied
    NoSuchProcess = psutil.NoSuchProcess
    ZombieProcess = psutil.ZombieProcess

    def __init__(self, processes):
        self.processes = processes

    def process_iter(self, attrs):
        assert set(attrs) == {'pid', 'name', 'memory_info', 'create_time'}
        return iter(self.processes)


class ProcessGroupsTests(unittest.TestCase):
    def test_same_executable_and_session_group_without_hardcoded_names(self):
        processes = [
            FakeProcess(11, 'custom.exe', 150, 10, r'C:\Apps\Custom\custom.exe'),
            FakeProcess(12, 'custom.exe', 50, 11, r'c:\apps\CUSTOM\CUSTOM.EXE'),
            FakeProcess(13, 'custom.exe', 70, 12, r'C:\Different\custom.exe'),
        ]
        with patch.object(pi, 'process_session_id', return_value=7), \
             patch.object(pi, 'executable_description', return_value='Aplicación personalizada'):
            result, total = pi.capture_processes(FakePsutil(processes), lambda pid, name: False)
        self.assertEqual(3, total)
        self.assertEqual(3, len(result))
        self.assertEqual(result[0]['group_key'], result[2]['group_key'])
        self.assertNotEqual(result[0]['group_key'], result[1]['group_key'])
        self.assertEqual('Aplicación personalizada', result[0]['display_name'])
        self.assertEqual(150.0, result[0]['memory_mb'])
        self.assertEqual({'pid', 'name', 'started_at', 'memory_mb', 'can_terminate', 'group_key', 'display_name'}, set(result[0]))
        self.assertNotIn('C:', str(result))

    def test_missing_path_or_session_is_not_grouped_by_filename(self):
        records = [FakeProcess(11, 'python.exe', 10, 10, None), FakeProcess(12, 'python.exe', 30, 11, None)]
        with patch.object(pi, 'process_session_id', return_value=1):
            result, total = pi.capture_processes(FakePsutil(records), lambda *_: False)
        self.assertEqual(2, total)
        self.assertNotEqual(result[0]['group_key'], result[1]['group_key'])
        self.assertTrue(all(item['display_name'] == 'python.exe' for item in result))

    def test_generic_runtime_hosts_do_not_merge_unrelated_jobs(self):
        processes = [FakeProcess(21, 'python.exe', 120, 11, r'C:\Python\python.exe'),
                     FakeProcess(22, 'python.exe', 80, 12, r'C:\Python\python.exe')]
        with patch.object(pi, 'process_session_id', return_value=1), \
             patch.object(pi, 'executable_description', return_value='Python interpreter'):
            records, _ = pi.capture_processes(FakePsutil(processes), lambda *_: False)
        self.assertNotEqual(records[0]['group_key'], records[1]['group_key'])

    def test_session_differences_are_not_combined(self):
        records = [FakeProcess(11, 'app.exe', 25, 10, r'C:\Apps\app.exe'),
                   FakeProcess(12, 'app.exe', 10, 12, r'C:\Apps\app.exe')]
        with patch.object(pi, 'process_session_id', side_effect=[1, 2]), \
             patch.object(pi, 'executable_description', return_value=None):
            result, _ = pi.capture_processes(FakePsutil(records), lambda *_: False)
        self.assertNotEqual(result[0]['group_key'], result[1]['group_key'])

    def test_top_n_is_bounded_and_total_is_preserved(self):
        records = [FakeProcess(n + 1, 'app.exe', n + 1, n + 5, 'C:\\app.exe') for n in range(265)]
        with patch.object(pi, 'process_session_id', return_value=1), \
             patch.object(pi, 'executable_description', return_value='App'):
            result, total = pi.capture_processes(FakePsutil(records), lambda *_: False)
        self.assertEqual(265, total)
        self.assertEqual(250, len(result))
        self.assertEqual(265, result[0]['pid'])

    def test_protected_advisory_and_no_pid_zero(self):
        records = [FakeProcess(0, 'Idle', 0, 0, ''), FakeProcess(4, 'System', 20, 1, '')]
        with patch.object(pi, 'process_session_id', return_value=None):
            result, total = pi.capture_processes(FakePsutil(records), lambda pid, _: pid == 4)
        self.assertEqual(1, total)
        self.assertEqual([4], [item['pid'] for item in result])
        self.assertFalse(result[0]['can_terminate'])

    def test_bad_version_description_is_bounded_and_sanitized(self):
        self.assertEqual('A B', pi._clean_description(' A\x00\x01B\t'))
        self.assertIsNone(pi._clean_description('  '))
        self.assertEqual(160, len(pi._clean_description('x' * 200)))


if __name__ == '__main__':
    unittest.main()
