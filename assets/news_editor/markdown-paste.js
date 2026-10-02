import {safeEditorHref} from './authoring.js';

const languages = new Set(['plain', 'bash', 'css', 'html', 'javascript', 'json', 'php', 'python']);
const plain = text => [{text, marks: []}];

function inline(text) {
  const result = [];
  const add = (value, marks = [], href) => {
    if (!value) return;
    result.push({text: value, marks, ...(href ? {href} : {})});
  };
  const token = /(\*\*([^*\n]+)\*\*|\*([^*\n]+)\*|`([^`\n]+)`|\[([^\]\n]+)\]\(((?:[^()\s]|\([^()]*\))+)\))/gu;
  let cursor = 0;
  for (const match of text.matchAll(token)) {
    add(text.slice(cursor, match.index));
    if (match[2]) add(match[2], ['strong']);
    else if (match[3]) add(match[3], ['em']);
    else if (match[4]) add(match[4], ['code']);
    else {
      const href = safeEditorHref(match[6]);
      add(match[5], href ? ['link'] : [], href ?? undefined);
    }
    cursor = match.index + match[0].length;
  }
  add(text.slice(cursor));
  return result.length ? result : plain('');
}

function tableCells(line) {
  return line.trim().replace(/^\|/u, '').replace(/(?<!\\)\|$/u, '').split(/(?<!\\)\|/u).map(value => value.trim().replace(/\\\|/gu, '|'));
}

/** Parse a fixed Markdown subset only when structural syntax is present. No HTML is interpreted. */
function parseMarkdown(text, requireStructure) {
  if (typeof text !== 'string' || text.length > 50000 || /\0/u.test(text)) return null;
  const lines = text.replace(/\r\n?/gu, '\n').split('\n');
  if (lines.length > 300) return null;
  const blocks = [];
  let recognized = false;
  const push = block => { blocks.push(block); return blocks.length <= 100; };
  for (let i = 0; i < lines.length;) {
    const line = lines[i];
    if (!line.trim()) { i++; continue; }
    let match;
    if ((match = /^(`{3,}|~{3,})([a-z]*)\s*$/u.exec(line))) {
      const end = lines.findIndex((candidate, index) => index > i && candidate.trim() === match[1]);
      if (end < 0) return null;
      const language = languages.has(match[2]) ? match[2] : 'plain';
      if (!push({type: 'code', language: language || 'plain', text: lines.slice(i + 1, end).join('\n')})) return null;
      recognized = true; i = end + 1; continue;
    }
    if ((match = /^(#{1,3})\s+(.+)$/u.exec(line))) {
      if (!push({type: 'heading', level: match[1].length + 1, content: inline(match[2])})) return null;
      recognized = true; i++; continue;
    }
    if (/^\s*(?:---+|\*\*\*+)\s*$/u.test(line)) {
      if (!push({type: 'separator'})) return null;
      recognized = true; i++; continue;
    }
    if (/^>\s?/u.test(line)) {
      const quote = [];
      while (i < lines.length && /^>\s?/u.test(lines[i])) quote.push(lines[i++].replace(/^>\s?/u, ''));
      if (!push({type: 'quote', content: inline(quote.join('\n')), cite: ''})) return null;
      recognized = true; continue;
    }
    if ((match = /^\s{0,3}([-*+]\s+|\d+\.\s+)(.*)$/u.exec(line))) {
      const ordered = /^\d/u.test(match[1]); const items = [];
      while (i < lines.length) {
        const item = /^\s{0,3}([-*+]\s+|\d+\.\s+)(.*)$/u.exec(lines[i]);
        if (!item || /^\d/u.test(item[1]) !== ordered) break;
        items.push(inline(item[2])); i++;
      }
      if (!push({type: 'list', ordered, items})) return null;
      recognized = true; continue;
    }
    if (line.includes('|') && i + 2 < lines.length && /^\s*\|?[\s:|-]+\|?[\s:|-]*$/u.test(lines[i + 1])) {
      const header = tableCells(line);
      const delimiter = tableCells(lines[i + 1]);
      if (header.length >= 2 && header.length <= 10 && header.length === delimiter.length && delimiter.every(cell => /^:?-{3,}:?$/u.test(cell))) {
        const rows = [header.map(inline)]; i += 2;
        while (i < lines.length && lines[i].includes('|') && lines[i].trim()) {
          const cells = tableCells(lines[i]);
          if (cells.length !== header.length || rows.length >= 20) return null;
          rows.push(cells.map(inline)); i++;
        }
        if (!push({type: 'table', header: true, rows})) return null;
        recognized = true; continue;
      }
    }
    const paragraph = [line]; i++;
    while (i < lines.length && lines[i].trim() && !/^(?:#{1,3}\s|`{3,}|~{3,}|>\s?|\s{0,3}(?:[-*+]\s|\d+\.\s)|---+\s*$)/u.test(lines[i])) paragraph.push(lines[i++]);
    if (!push({type: 'paragraph', content: inline(paragraph.join('\n'))})) return null;
  }
  return (!requireStructure || recognized) && blocks.length ? blocks : null;
}

export const structuredMarkdown = text => parseMarkdown(text, true);
export const importMarkdownArticle = text => parseMarkdown(text, false);
