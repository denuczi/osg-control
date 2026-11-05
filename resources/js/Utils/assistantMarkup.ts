/**
 * Subconjunto deliberado de Markdown para respuestas del asistente.
 * Devuelve datos, nunca HTML: Vue escapa TODO el contenido al renderizar.
 * No se crean enlaces a partir del texto del modelo; los enlaces a equipos
 * se generan exclusivamente desde evidencias verificadas en AiEvidence.
 */
export type InlinePart = { kind: 'text' | 'strong' | 'em' | 'code' | 'link'; value: string; href?: string };

export type MessageBlock =
  | { kind: 'paragraph' | 'heading'; parts: InlinePart[]; level?: 2 | 3 | 4 }
  | { kind: 'unordered' | 'ordered'; items: InlinePart[][]; start: number }
  | { kind: 'code'; value: string }
  | { kind: 'table'; headers: InlinePart[][]; rows: InlinePart[][][] };

function isEscapable(char: string): boolean {
  return /[\\`*_{}\[\]()#+.!<>|~-]/.test(char);
}

function unescapeMarkup(value: string): string {
  return value.replace(/\\([\\`*_{}\[\]()#+.!<>|~-])/g, '$1');
}

function closingMarker(text: string, marker: string, from: number): number {
  let position = from;
  while (position < text.length) {
    const index = text.indexOf(marker, position);
    if (index === -1) return -1;
    if (index > 0 && text[index - 1] === '\\') {
      position = index + marker.length;
      continue;
    }
    if (index > from) return index;
    position = index + marker.length;
  }
  return -1;
}

/** Only external HTTPS links are clickable. JavaScript/data URLs remain escaped text. */
function safeLink(raw: string): string | null {
  if (!/^https:\/\/[^\s<>]+$/i.test(raw)) return null;
  try {
    const url = new URL(raw);
    return url.protocol === 'https:' && url.hostname && !url.username && !url.password ? url.href : null;
  } catch { return null; }
}

export function parseAssistantInline(text: string): InlinePart[] {
  const parts: InlinePart[] = [];
  let pending = '';
  function flush(): void {
    if (pending) parts.push({ kind: 'text', value: pending });
    pending = '';
  }
  let index = 0;
  while (index < text.length) {
    const char = text[index] ?? '';
    if (char === '\\' && index + 1 < text.length && isEscapable(text[index + 1] ?? '')) {
      pending += text[index + 1];
      index += 2;
      continue;
    }
    if (char === '[') {
      const match = /^\[([^\]\n]{1,180})\]\((https:\/\/[^\s)<>]+)\)/i.exec(text.slice(index));
      if (match) {
        const href = safeLink(match[2] ?? '');
        if (href !== null) {
          flush();
          parts.push({ kind: 'link', value: unescapeMarkup(match[1] ?? ''), href });
          index += match[0].length;
          continue;
        }
      }
    }
    const marker = text.startsWith('**', index) ? '**'
      : char === '`' ? '`' : char === '*' ? '*' : null;
    if (marker !== null) {
      const end = closingMarker(text, marker, index + marker.length);
      if (end >= 0) {
        flush();
        parts.push({
          kind: marker === '**' ? 'strong' : marker === '*' ? 'em' : 'code',
          value: marker === '`'
            ? text.slice(index + marker.length, end)
            : unescapeMarkup(text.slice(index + marker.length, end)),
        });
        index = end + marker.length;
        continue;
      }
    }
    pending += char;
    index++;
  }
  flush();
  return parts;
}

function listItem(line: string): { kind: 'ordered' | 'unordered'; body: string; start: number } | null {
  const ordered = /^\s{0,3}(\d{1,3})[.)]\s+(.+)$/.exec(line);
  if (ordered) return { kind: 'ordered', body: ordered[2] ?? '', start: Number(ordered[1]) };
  const unordered = /^\s{0,3}[-+*]\s+(.+)$/.exec(line);
  if (unordered) return { kind: 'unordered', body: unordered[1] ?? '', start: 1 };
  return null;
}

/** Tables are parsed as plain data; they never become arbitrary HTML. */
function splitTableRow(line: string): string[] {
  const trimmed = line.trim().replace(/^\|/, '').replace(/(?<!\\)\|$/, '');
  const cells: string[] = [];
  let buffer = '';
  for (let i = 0; i < trimmed.length; i++) {
    const char = trimmed[i] ?? '';
    if (char === '\\' && trimmed[i + 1] === '|') {
      buffer += '\\|';
      i++;
    } else if (char === '|') {
      cells.push(buffer.trim());
      buffer = '';
    } else {
      buffer += char;
    }
  }
  cells.push(buffer.trim());
  return cells;
}
function isTableStart(lines: string[], position: number): boolean {
  const header = lines[position] ?? '';
  const separator = lines[position + 1] ?? '';
  if (!header.includes('|') || !separator.includes('-')) return false;
  const columns = splitTableRow(header);
  const separatorColumns = splitTableRow(separator);
  return columns.length >= 2 && columns.length <= 8 &&
    columns.length === separatorColumns.length &&
    separatorColumns.every(cell => /^:?-{3,}:?$/.test(cell));
}

export function parseAssistantMessage(input: string): MessageBlock[] {
  const lines = input.replace(/\r\n?/g, '\n').split('\n');
  const blocks: MessageBlock[] = [];
  let index = 0;
  while (index < lines.length) {
    const line = lines[index] ?? '';
    if (!line.trim()) { index++; continue; }

    if (/^\s{0,3}```/.test(line)) {
      const collected: string[] = [];
      index++;
      while (index < lines.length && !/^\s{0,3}```\s*$/.test(lines[index] ?? '')) {
        collected.push(lines[index] ?? '');
        index++;
      }
      if (index < lines.length) index++;
      blocks.push({ kind: 'code', value: collected.join('\n') });
      continue;
    }

    const heading = /^\s{0,3}(#{1,3})\s+(.+)$/.exec(line);
    if (heading) {
      blocks.push({ kind: 'heading', level: (heading[1]?.length ?? 1) + 1 as 2 | 3 | 4, parts: parseAssistantInline(heading[2] ?? '') });
      index++;
      continue;
    }

    if (isTableStart(lines, index)) {
      const headers = splitTableRow(line).map(parseAssistantInline);
      index += 2;
      const rows: InlinePart[][][] = [];
      while (index < lines.length && rows.length < 30 && (lines[index] ?? '').includes('|')) {
        const cells = splitTableRow(lines[index] ?? '');
        if (cells.length !== headers.length) break;
        rows.push(cells.map(parseAssistantInline));
        index++;
      }
      blocks.push({ kind: 'table', headers, rows });
      continue;
    }

    const firstItem = listItem(line);
    if (firstItem !== null) {
      const items: InlinePart[][] = [];
      while (index < lines.length) {
        const next = listItem(lines[index] ?? '');
        if (!next || next.kind !== firstItem.kind) break;
        items.push(parseAssistantInline(next.body));
        index++;
      }
      blocks.push({ kind: firstItem.kind, items, start: firstItem.start });
      continue;
    }

    const paragraph: string[] = [];
    while (index < lines.length) {
      const next = lines[index] ?? '';
      if (!next.trim() || (paragraph.length > 0 && (listItem(next) !== null || isTableStart(lines, index) || /^\s{0,3}(?:#{1,3}\s+|```)/.test(next)))) break;
      paragraph.push(next.trim());
      index++;
    }
    blocks.push({ kind: 'paragraph', parts: parseAssistantInline(paragraph.join('\n')) });
  }
  return blocks;
}
