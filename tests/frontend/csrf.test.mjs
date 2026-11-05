import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join, dirname } from 'node:path';
import { xsrfTokenFromCookie, csrfHeaders } from '../../resources/js/Utils/csrf.ts';

const base = join(dirname(fileURLToPath(import.meta.url)), '../..');

test('Lee y decodifica la cookie XSRF-TOKEN sin alterar valores cifrados', () => {
  assert.equal(xsrfTokenFromCookie('session=abc; XSRF-TOKEN=eyJpdiI6ImErYiUyRj0%3D; otro=xyz'), 'eyJpdiI6ImErYiUyRj0=');
  assert.deepEqual(csrfHeaders('session=abc; XSRF-TOKEN=abc%2Bdef%2F%3D'), { 'X-XSRF-TOKEN': 'abc+def/=' });
});

test('No confunde otras cookies y falla cerrado si no hay token válido', () => {
  assert.equal(xsrfTokenFromCookie('NOT-XSRF-TOKEN=foo; session=bar'), null);
  assert.deepEqual(csrfHeaders('session=bar'), {});
  assert.equal(xsrfTokenFromCookie('XSRF-TOKEN=%E0%A4%A'), null);
});

test('Ambos botones envían X-XSRF-TOKEN desde cookie para métodos mutantes', () => {
  for (const file of ['GenerateFleetReportButton.vue', 'GenerateReportButton.vue']) {
    const source = readFileSync(join(base, 'resources/js/Components/Reports', file), 'utf8');
    assert.match(source, /csrfHeaders\(document\.cookie\)/, file);
    assert.doesNotMatch(source, /meta\[name=[\"']csrf-token/, file);
    assert.doesNotMatch(source, /X-CSRF-TOKEN/, file);
    assert.match(source, /419/, file);
  }
});

test('La plantilla base no expone un meta CSRF que quede obsoleto tras iniciar sesión', () => {
  const source = readFileSync(join(base, 'resources/views/app.blade.php'), 'utf8');
  assert.doesNotMatch(source, /meta name="csrf-token"/);
});
