import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseAssistantInline, parseAssistantMessage } from '../../resources/js/Utils/assistantMarkup.ts';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');

test('renderiza negritas y texto alrededor sin mostrar los delimitadores', () => {
  assert.deepEqual(parseAssistantInline('Equipo **DESKTOP-RAVF7FN**: **3,9 GiB**'), [
    { kind: 'text', value: 'Equipo ' }, { kind: 'strong', value: 'DESKTOP-RAVF7FN' },
    { kind: 'text', value: ': ' }, { kind: 'strong', value: '3,9 GiB' },
  ]);
});

test('no elimina asteriscos literales escapados ni inventa negritas con sintaxis incompleta', () => {
  assert.deepEqual(parseAssistantInline('literal \\*\\*nombre\\*\\* y **sin cerrar'), [
    { kind: 'text', value: 'literal **nombre** y **sin cerrar' },
  ]);
});

test('admite párrafos, listas ordenadas, viñetas y código', () => {
  const blocks = parseAssistantMessage('**Equipos registrados**\n\n1. **LAB-01** — 8 GiB\n2. **LAB-02** — 16 GiB\n\n- Desconectados\n- Sin datos\n\n`C:`');
  assert.deepEqual(blocks.map(x => x.kind), ['paragraph', 'ordered', 'unordered', 'paragraph']);
  assert.equal(blocks[1].items[0][0].kind, 'strong');
  assert.equal(blocks[2].items.length, 2);
  assert.equal(blocks[3].parts[0].kind, 'code');
});

test('las etiquetas HTML y pseudo-enlaces peligrosos siguen siendo datos, no HTML ejecutable', () => {
  const blocks = parseAssistantMessage('**<img src=x onerror=alert(1)>**\n[abrir](javascript:alert(1))');
  assert.equal(blocks[0].parts[0].value, '<img src=x onerror=alert(1)>');
  assert.match(blocks[0].parts[1].value, /javascript:alert/);
  const component = readFileSync(join(root, 'resources/js/Components/Assistant/AiFormattedText.vue'), 'utf8');
  const child = readFileSync(join(root, 'resources/js/Components/Assistant/AiInlineText.vue'), 'utf8');
  assert.doesNotMatch(component + child, /v-html|innerHTML|createElement\(/);
  assert.match(child, /\{\{ part\.value \}\}/);
});

test('procesa código cercado como texto literal', () => {
  assert.deepEqual(parseAssistantMessage('```html\n<script>alert(1)</script>\n```'), [
    { kind: 'code', value: '<script>alert(1)</script>' },
  ]);
});

test('el chat aplica formato a la IA sin transformar el texto del administrador', () => {
  const source = readFileSync(join(root, 'resources/js/Components/Assistant/AiConversation.vue'), 'utf8');
  assert.match(source, /v-if="message\.role === 'assistant'"/);
  assert.match(source, /<template v-else>\{\{ message\.content \}\}<\/template>/);
});

test('renderiza tablas Markdown y celdas con caracteres escapados sin generar HTML', () => {
  const blocks = parseAssistantMessage('Comparación de equipos\n\n| Equipo | RAM |\n| --- | --- |\n| **LAB-PC** | 8 GiB |\n| NETBOOK | 16 GiB y valor \\| adicional |');
  assert.deepEqual(blocks.map(block => block.kind), ['paragraph', 'table']);
  assert.equal(blocks[1].headers.length, 2);
  assert.equal(blocks[1].rows.length, 2);
  assert.deepEqual(blocks[1].rows[0][0], [{ kind: 'strong', value: 'LAB-PC' }]);
  assert.equal(blocks[1].rows[1][1][0].value, '16 GiB y valor | adicional');
  const component = readFileSync(join(root, 'resources/js/Components/Assistant/AiFormattedText.vue'), 'utf8');
  assert.match(component, /block\.kind === 'table'/);
  assert.doesNotMatch(component, /v-html|innerHTML/);
});
