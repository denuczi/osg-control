"""Static regression checks for OSG Docker packaging. Not a replacement for docker build/up."""
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import yaml

ROOT = Path(__file__).resolve().parents[2]
modspec = importlib.util.spec_from_file_location('configurator', ROOT/'scripts/docker/configure.py')
config = importlib.util.module_from_spec(modspec)
modspec.loader.exec_module(config)

class DockerDeploymentTest(unittest.TestCase):
    def test_service_layout_and_limits(self):
        data = yaml.safe_load((ROOT/'compose.yaml').read_text())
        services = data['services']
        self.assertEqual(set(services), {'init','app','web','worker','scheduler'})
        limits = [services[x]['mem_limit'] for x in ('app','worker','scheduler','web')]
        def mib(s):
            return int(s[:-1]) * (1024 if s.endswith('g') else 1)
        self.assertLessEqual(sum(map(mib,limits)), 5*1024)
        self.assertEqual(services['init']['restart'], 'no')
        self.assertEqual(services['worker']['command'][4], '--queue=reports')
        self.assertIn('${OSG_BIND_IP:-127.0.0.1}', services['web']['ports'][0])
        self.assertEqual(services['app']['depends_on']['init']['condition'], 'service_completed_successfully')
    def test_no_host_mount_for_sqlite_and_no_env_in_image(self):
        compose = yaml.safe_load((ROOT/'compose.yaml').read_text())
        mounts = compose['services']['app']['volumes']
        self.assertIn('osg-db:/data/sqlite', mounts)
        self.assertIn('osg-storage:/var/www/html/storage', mounts)
        self.assertNotIn('./database:/var/www/html/database', mounts)
        ignored = (ROOT/'.dockerignore').read_text().splitlines()
        self.assertIn('.env', ignored)
        self.assertIn('secrets', ignored)
        self.assertIn('agent', ignored)
    def test_tls_optional_only(self):
        base = yaml.safe_load((ROOT/'compose.yaml').read_text())
        tls = yaml.safe_load((ROOT/'compose.https.yaml').read_text())
        self.assertNotIn('tls', base['services'])
        self.assertIn('tls', tls['services'])
    def test_config_first_run_and_idempotence(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root/'.env.example').write_text((ROOT/'.env.example').read_text())
            with patch.object(config,'ROOT',root),patch.object(config,'RUNTIME',root/'secrets'),patch.object(config,'APP_ENV',root/'secrets/laravel.env'),patch.object(config,'COMPOSE_ENV',root/'.env'),patch('builtins.input',return_value='Laboratorio'):
                config.run('init')
                app = (root/'secrets/laravel.env').read_text()
                self.assertIn('APP_ENV=production', app)
                self.assertIn('NODO_ASSISTANT_ENABLED=false', app)
                self.assertIn('DB_DATABASE=/data/sqlite/database.sqlite', app)
                self.assertIn('APP_NAME=Laboratorio', app)
                self.assertIn('APP_KEY=base64:', app)
                self.assertEqual((root/'secrets').stat().st_mode & 0o777,0o700)
                self.assertEqual((root/'.env').stat().st_mode & 0o777,0o600)
                with self.assertRaises(RuntimeError): config.run('init')
                self.assertEqual((root/'secrets/laravel.env').read_text(),app)
    def test_no_implicit_lan_http(self):
        with patch('builtins.input',side_effect=['lan','','NO']):
            with self.assertRaises(ValueError):
                config.update_network(['APP_NAME=OSG'], ['OSG_PORT=8088'])
    def test_api_key_never_in_stdout(self):
        with patch('builtins.input',side_effect=['groq','']),patch('getpass.getpass',return_value='SECRET123'):
            lines = config.update_api(['NODO_ASSISTANT_ENABLED=false'])
        self.assertEqual(config.get_value(lines,'GROQ_API_KEY'),'SECRET123')
        self.assertEqual(config.get_value(lines,'NODO_ASSISTANT_ENABLED'),'true')
    def test_name_change_preserves_key(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root/'.env.example').write_text((ROOT/'.env.example').read_text())
            with patch.object(config,'ROOT',root),patch.object(config,'RUNTIME',root/'secrets'),patch.object(config,'APP_ENV',root/'secrets/laravel.env'),patch.object(config,'COMPOSE_ENV',root/'.env'):
                with patch('builtins.input',return_value='OSG Nodo Control'): config.run('init')
                key = config.get_value(config.read_lines(root/'secrets/laravel.env'),'APP_KEY')
                with patch('builtins.input',return_value='Laboratorio B'): config.run('name')
                new_lines = config.read_lines(root/'secrets/laravel.env')
                self.assertEqual(config.get_value(new_lines,'APP_KEY'),key)
                self.assertEqual(config.get_value(new_lines,'APP_NAME'),'Laboratorio B')

    def test_tls_requires_both_cert_files(self):
        with tempfile.TemporaryDirectory() as directory:
            with patch.object(config,'RUNTIME',Path(directory)):
                with patch('builtins.input',side_effect=['osg.example.com','8443']):
                    with self.assertRaises(ValueError):
                        config.update_tls(['APP_URL=http://127.0.0.1:8088'],['OSG_PORT=8088'])

    def test_runtime_not_built_with_agent_or_credentials(self):
        dockerfile = (ROOT/'Dockerfile').read_text()
        ignored = (ROOT/'.dockerignore').read_text().splitlines()
        self.assertIn('USER www-data',dockerfile)
        self.assertIn('secrets',ignored)
        self.assertIn('agent',ignored)
        self.assertNotIn('php artisan serve',dockerfile)
        self.assertNotIn('GROQ_API_KEY=',dockerfile)

    def test_php_backup_restricted_destination(self):
        backup = (ROOT/'docker/runtime/sqlite-backup.php').read_text()
        self.assertIn('->backup($dest)',backup)
        self.assertIn('No se sobrescribe',backup)

    def test_init_can_prepare_volumes_and_drop_privileges(self):
        services = yaml.safe_load((ROOT/'compose.yaml').read_text())['services']
        self.assertTrue({'CHOWN', 'DAC_OVERRIDE', 'SETUID', 'SETGID'} <= set(services['init']['cap_add']))
        for name in ('app', 'worker', 'scheduler'):
            self.assertEqual(services[name]['user'], '33:33')
            self.assertNotIn('cap_add', services[name])

    def test_nginx_cache_is_writable_by_its_user(self):
        web = yaml.safe_load((ROOT/'compose.yaml').read_text())['services']['web']
        cache = next(mount for mount in web['tmpfs'] if mount.startswith('/var/cache/nginx:'))
        self.assertIn('uid=101', cache)
        self.assertIn('gid=101', cache)

    def test_php_healthcheck_preserves_variables_through_compose(self):
        app = yaml.safe_load((ROOT/'compose.yaml').read_text())['services']['app']
        self.assertIn('$$e, $$s', app['healthcheck']['test'][-1])

if __name__ == '__main__':
    unittest.main()
