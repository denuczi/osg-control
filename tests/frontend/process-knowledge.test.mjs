import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseAssistantInline, parseAssistantMessage } from '../../resources/js/Utils/assistantMarkup.ts';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
test('Markdown supports headings, safe links, inline formatting and escapes HTML', () => {
  const blocks = parseAssistantMessage('## ¿Qué es chrome.exe?\n\n**Ejecutable** `chrome.exe`\n\n[Microsoft](https://learn.microsoft.com/windows)');
  assert.deepEqual(blocks.map(b => b.kind), ['heading', 'paragraph', 'paragraph']);
  assert.equal(blocks[0].level, 3);
  assert.equal(blocks[1].parts[0].kind, 'strong');
  assert.equal(blocks[2].parts[0].kind, 'link');
  assert.match(blocks[2].parts[0].href, /^https:\/\/learn.microsoft.com/);
  assert.deepEqual(parseAssistantInline('[ataque](javascript:alert(1))'), [{kind:'text',value:'[ataque](javascript:alert(1))'}]);
  assert.equal(parseAssistantInline('<script>alert(1)</script>')[0].kind, 'text');
});
test('dialog handles focus, cleanup, abort and is never HTML-injected', () => {
  const modal = readFileSync(join(root, 'resources/js/Components/ProcessInfoModal.vue'), 'utf8');
  const formatted = readFileSync(join(root, 'resources/js/Components/Assistant/AiFormattedText.vue'), 'utf8');
  assert.match(modal, /controller\?\.abort\(\)/);
  assert.match(modal, /document\.removeEventListener/);
  assert.match(modal, /previousFocus\.focus|previousFocus\?\.focus/);
  assert.match(modal, /role="dialog" aria-modal="true"/);
  assert.doesNotMatch(modal + formatted, /v-html|innerHTML/);
});
