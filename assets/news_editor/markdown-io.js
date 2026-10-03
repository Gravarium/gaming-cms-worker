import {safeEditorHref} from './authoring.js';

const escapeText = value => value.replace(/\\/gu, '\\\\').replace(/([*`\[\]<>])/gu, '\\$1');

function rich(runs) {
  return runs.map(run => {
    let text = escapeText(run.text);
    const marks = Array.isArray(run.marks) ? run.marks : [];
    if (marks.includes('code')) text = '`' + run.text.replace(/`/gu, '\\`') + '`';
    if (marks.includes('strong')) text = '**' + text + '**';
    if (marks.includes('em')) text = '*' + text + '*';
    if (marks.includes('strike')) text = '~~' + text + '~~';
    if (marks.includes('link') && safeEditorHref(run.href)) text = '[' + text + '](' + run.href + ')';
    return text;
  }).join('');
}

function blockMarkdown(block) {
  switch (block.type) {
    case 'paragraph': return rich(block.content);
    case 'heading': return '#'.repeat(Math.max(1, Math.min(3, block.level - 1))) + ' ' + rich(block.content);
    case 'quote': return rich(block.content).split('\n').map(line => '> ' + line).join('\n') + (block.cite ? '\n> — ' + escapeText(block.cite) : '');
    case 'list': return block.items.map((item, index) => (block.ordered ? index + 1 + '. ' : '- ') + rich(item)).join('\n');
    case 'table': {
      const rows = block.rows.map(row => '| ' + row.map(cell => rich(cell).replace(/\|/gu, '\\|')).join(' | ') + ' |');
      if (block.header !== false) rows.splice(1, 0, '| ' + block.rows[0].map(() => '---').join(' | ') + ' |');
      return rows.join('\n');
    }
    case 'code': { const fence = block.text.includes('```') ? '~~~' : '```'; return fence + (block.language === 'plain' ? '' : block.language) + '\n' + block.text + '\n' + fence; }
    case 'separator': return '---';
    case 'callout': return '> ' + ({info: 'Info', tip: 'Tipp', warning: 'Warnung'}[block.tone] ?? 'Info') + ': ' + rich(block.content);
    case 'media': return block.decorative
      ? 'Dekoratives Bild aus CMS-Mediathek #' + block.assetId + ' (ohne Alternativtext)'
      : 'Bild aus CMS-Mediathek #' + block.assetId + ': ' + escapeText(block.alt || block.caption || 'Ohne Beschreibung');
    case 'video': return 'Video (' + block.provider + '): ' + escapeText(block.videoId) + (block.caption ? ' — ' + escapeText(block.caption) : '');
    default: return '';
  }
}

/** Portable text export. CMS-owned image/video references are represented as readable text. */
export function articleMarkdown(document) {
  if (document?.version !== 2 || !Array.isArray(document.blocks)) return null;
  return document.blocks.map(blockMarkdown).filter(Boolean).join('\n\n') + '\n';
}
