"""Interactive API configuration tests; never use real credentials or network."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import MagicMock, patch
from urllib.error import HTTPError, URLError

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('api_configurator', ROOT/'scripts/docker/configure.py')
config = importlib.util.module_from_spec(spec)
spec.loader.exec_module(config)
KEY = 'private-test-key-DO-NOT-PRINT'
MODEL = 'openai/gpt-oss-20b'
BASE = ['APP_KEY=base64:original', 'NODO_ASSISTANT_ENABLED=false',
        'REPORT_AI_PROVIDER=groq', 'REPORT_AI_MODEL='+MODEL, 'GROQ_API_KEY=original-key']


class ApiConfigurationTest(unittest.TestCase):
    @contextlib.contextmanager
    def wizard(self, answers, payload=None, error=None, key=KEY):
        output = io.StringIO()
        opener = MagicMock()
        if error:
            opener.open.side_effect = error
        else:
            raw = json.dumps(payload if payload is not None else {'data': [{'id': MODEL}]}).encode()
            opener.open.return_value.__enter__.return_value.read.return_value = raw
        try:
            with patch('builtins.input', side_effect=answers), patch('getpass.getpass', return_value=key), \
                    patch.object(config, 'build_opener', return_value=opener), contextlib.redirect_stdout(output):
                yield opener, output
        finally:
            if key:
                self.assertNotIn(key, output.getvalue())

    @contextlib.contextmanager
    def installation(self):
        with tempfile.TemporaryDirectory() as directory, contextlib.ExitStack() as stack:
            root = Path(directory)
            runtime = root/'secrets'
            runtime.mkdir(mode=0o700)
            app = runtime/'laravel.env'
            compose = root/'.env'
            app.write_text('\n'.join(BASE)+'\n')
            compose.write_text('OSG_PORT=8088\n')
            for name, path in [('ROOT', root), ('RUNTIME', runtime), ('APP_ENV', app), ('COMPOSE_ENV', compose)]:
                stack.enter_context(patch.object(config, name, path))
            yield app, compose

    def test_confirmed_valid_model_saves_only_app_environment(self):
        with self.installation() as (app, compose), self.wizard(['groq', '', 's']) as (opener, _), \
                patch.object(config, 'persist', wraps=config.persist) as persist:
            config.run('api')
            self.assertIn('GROQ_API_KEY='+KEY, app.read_text())
            self.assertIn('APP_KEY=base64:original', app.read_text())
            self.assertEqual(compose.read_text(), 'OSG_PORT=8088\n')
            self.assertEqual(persist.call_count, 1)
            request = opener.open.call_args.args[0]
            self.assertEqual(request.full_url, 'https://api.groq.com/openai/v1/models')
            self.assertEqual(request.get_header('Authorization'), 'Bearer '+KEY)
            self.assertEqual(opener.open.call_args.kwargs['timeout'], 10)

    def test_default_confirmation_cancels_without_network_or_file_changes(self):
        with self.installation() as (app, compose), self.wizard(['groq', '', '']) as (opener, _):
            before = (app.read_bytes(), compose.read_bytes())
            config.run('api')
            self.assertEqual(before, (app.read_bytes(), compose.read_bytes()))
            opener.open.assert_not_called()

    def test_dollar_characters_in_key_are_preserved_as_literal_dotenv_values(self):
        key = 'private${UNSET_VARIABLE}-key'
        with self.installation() as (app, _), self.wizard(['groq', '', 's'], key=key) as (opener, _):
            config.run('api')
            self.assertIn('GROQ_API_KEY="private\\${UNSET_VARIABLE}-key"', app.read_text())
            self.assertEqual(config.get_value(config.read_lines(app), 'GROQ_API_KEY'), key)
            self.assertEqual(opener.open.call_args.args[0].get_header('Authorization'), 'Bearer '+key)

    def test_misspelled_model_does_not_replace_configuration(self):
        with self.installation() as (app, compose), self.wizard(['groq', 'openai/gpt-oss-2', 's']):
            before = (app.read_bytes(), compose.read_bytes())
            with self.assertRaisesRegex(ValueError, 'modelo no aparece'):
                config.run('api')
            self.assertEqual(before, (app.read_bytes(), compose.read_bytes()))

    def test_provider_errors_preserve_files_without_disclosing_key(self):
        errors = [URLError('private internal error '+KEY), TimeoutError(KEY)]
        errors += [HTTPError('https://api.groq.com/openai/v1/models', code, KEY, {}, io.BytesIO(KEY.encode()))
                   for code in (401, 403, 404, 429, 500, 302)]
        for error in errors:
            with self.subTest(error=type(error).__name__, code=getattr(error, 'code', None)), \
                    self.installation() as (app, compose), self.wizard(['groq', '', 's'], error=error) as (_, output):
                before = (app.read_bytes(), compose.read_bytes())
                with self.assertRaises(ValueError) as caught:
                    config.run('api')
                self.assertNotIn(KEY, str(caught.exception))
                self.assertNotIn(KEY, output.getvalue())
                self.assertEqual(before, (app.read_bytes(), compose.read_bytes()))

    def test_invalid_or_oversized_responses_do_not_replace_configuration(self):
        for raw in [b'{', b'[]', b'{"data": {}}', b'x'*(1024*1024+1)]:
            with self.subTest(size=len(raw)), self.installation() as (app, compose), \
                    self.wizard(['groq', '', 's']) as (opener, _):
                opener.open.return_value.__enter__.return_value.read.return_value = raw
                before = (app.read_bytes(), compose.read_bytes())
                with self.assertRaises(ValueError):
                    config.run('api')
                self.assertEqual(before, (app.read_bytes(), compose.read_bytes()))

    def test_disabled_catalog_model_is_rejected(self):
        with self.wizard(['groq', '', 's'], payload={'data': [{'id': MODEL, 'active': False}]}):
            with self.assertRaisesRegex(ValueError, 'modelo no aparece'):
                config.update_api(BASE)

    def test_openai_uses_its_own_endpoint_and_selected_model(self):
        with self.wizard(['openai', 'selected-model', 'sí'], payload={'data': [{'id': 'selected-model'}]}) as (opener, _):
            result = config.update_api(BASE)
            self.assertEqual(config.get_value(result, 'REPORT_AI_PROVIDER'), 'openai')
            self.assertEqual(config.get_value(result, 'OPENAI_API_KEY'), KEY)
            self.assertEqual(opener.open.call_args.args[0].full_url, 'https://api.openai.com/v1/models')

    def test_compatible_catalog_failure_requires_exact_explicit_override(self):
        for answer in ['', 's', 'GUARDAR_SIN_VERIFICAR']:
            with self.subTest(answer=answer), self.wizard(
                ['compatible', 'custom-model', 'https://provider.example/v1', 's', answer],
                error=HTTPError('https://provider.example/v1/models', 404, 'Not found', {}, None),
            ):
                source = BASE.copy()
                result = config.update_api(source)
                self.assertEqual(source, BASE)
                if answer == 'GUARDAR_SIN_VERIFICAR':
                    self.assertEqual(config.get_value(result, 'REPORT_AI_MODEL'), 'custom-model')
                else:
                    self.assertIsNone(result)

    def test_compatible_authentication_failure_cannot_be_bypassed(self):
        with self.installation() as (app, compose), self.wizard(
            ['compatible', 'custom-model', 'https://provider.example/v1', 's', 'GUARDAR_SIN_VERIFICAR'],
            error=HTTPError('https://provider.example/v1/models', 401, 'Unauthorized', {}, None),
        ):
            before = (app.read_bytes(), compose.read_bytes())
            with self.assertRaisesRegex(ValueError, 'rechazó la clave'):
                config.run('api')
            self.assertEqual(before, (app.read_bytes(), compose.read_bytes()))

    def test_invalid_keys_never_reach_confirmation_or_network(self):
        for key in ['', ' leading-space', 'line\nbreak', 'x'*4097]:
            with self.subTest(length=len(key)), self.installation() as (app, compose), \
                    self.wizard(['groq', ''], key=key) as (opener, _):
                before = (app.read_bytes(), compose.read_bytes())
                with self.assertRaisesRegex(ValueError, 'Clave vacía'):
                    config.run('api')
                self.assertEqual(before, (app.read_bytes(), compose.read_bytes()))
                opener.open.assert_not_called()

    def test_disabling_assistant_requires_confirmation_and_preserves_keys(self):
        for answer in ['', 's']:
            with self.subTest(answer=answer), self.wizard(['ninguna', answer]) as (opener, _):
                source = BASE + ['NODO_OTHER_SETTING=1']
                result = config.update_api(source)
                self.assertEqual(source, BASE + ['NODO_OTHER_SETTING=1'])
                opener.open.assert_not_called()
                if answer == 's':
                    self.assertEqual(config.get_value(result, 'GROQ_API_KEY'), 'original-key')
                    self.assertEqual(config.get_value(result, 'NODO_OTHER_SETTING'), '1')
                else:
                    self.assertIsNone(result)

    def test_interruption_before_confirmation_preserves_files(self):
        for interruption in [EOFError(), KeyboardInterrupt()]:
            with self.subTest(kind=type(interruption)), self.installation() as (app, compose), \
                    self.wizard(['groq', '', interruption]) as (opener, _):
                before = (app.read_bytes(), compose.read_bytes())
                with self.assertRaises(type(interruption)):
                    config.run('api')
                self.assertEqual(before, (app.read_bytes(), compose.read_bytes()))
                opener.open.assert_not_called()

    def test_secret_cannot_fall_back_to_visible_terminal_input(self):
        with self.installation() as (app, compose), self.wizard(['groq', '']), \
                patch('getpass.getpass', side_effect=config.getpass.GetPassWarning('hidden input unavailable')):
            before = (app.read_bytes(), compose.read_bytes())
            with self.assertRaisesRegex(ValueError, 'terminal'):
                config.run('api')
            self.assertEqual(before, (app.read_bytes(), compose.read_bytes()))


if __name__ == '__main__':
    unittest.main()
