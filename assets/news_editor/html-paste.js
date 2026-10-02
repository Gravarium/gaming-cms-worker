import {safeEditorHref} from './authoring.js';

const ignored = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'MATH', 'FORM', 'INPUT', 'BUTTON', 'META', 'LINK']);
const blockTags = new Set(['P', 'DIV', 'H1', 'H2', 'H3', 'H4', 'BLOCKQUOTE', 'UL', 'OL', 'PRE', 'HR', 'TABLE']);
const markFor = {STRONG: 'strong', B: 'strong', EM: 'em', I: 'em', U: 'underline', S: 'strike', STRIKE: 'strike', DEL: 'strike', CODE: 'code'};

function children(node) {
  return Array.from(node?.childNodes ?? []);
}

function textOf(node) {
  if (node?.nodeType === 3) return String(node.nodeValue ?? '');
  return children(node).map(textOf).join('');
}

function runs(node, marks = [], href) {
  if (node?.nodeType === 3) return node.nodeValue ? [{text: String(node.nodeValue), marks, ...(href ? {href} : {})}] : [];
  if (node?.nodeType !== 1 || ignored.has(node.tagName)) return [];
  if (node.tagName === 'BR') return [{text: '\n', marks: []}];
  if (node.tagName === 'IMG') {
    const alt = String(node.getAttribute?.('alt') ?? '').trim();
    return alt ? [{text: '[Bild: ' + alt + ']', marks: []}] : [];
  }
  const candidateHref = node.tagName === 'A' ? safeEditorHref(node.getAttribute?.('href')) : href;
  const mark = node.tagName === 'A' && candidateHref ? 'link' : markFor[node.tagName];
  const nextMarks = mark && !marks.includes(mark) ? [...marks, mark] : marks;
  return children(node).flatMap(child => runs(child, nextMarks, candidateHref ?? undefined));
}

function normalizedRuns(node) {
  const result = runs(node);
  return result.length ? result : [{text: '', marks: []}];
}

function convertContainer(node, output) {
  for (const child of children(node)) {
    if (output.length >= 100 || child?.nodeType !== 1 || ignored.has(child.tagName)) continue;
    const tag = child.tagName;
    if (tag === 'DIV' && children(child).some(item => item?.nodeType === 1 && blockTags.has(item.tagName))) {
      convertContainer(child, output); continue;
    }
    if (tag === 'P' || tag === 'DIV') output.push({type: 'paragraph', content: normalizedRuns(child)});
    else if (/^H[1-4]$/u.test(tag)) output.push({type: 'heading', level: Math.max(2, Math.min(4, Number(tag[1]))), content: normalizedRuns(child)});
    else if (tag === 'BLOCKQUOTE') output.push({type: 'quote', content: normalizedRuns(child), cite: ''});
    else if (tag === 'UL' || tag === 'OL') {
      const items = children(child).filter(item => item?.tagName === 'LI').slice(0, 100).map(normalizedRuns);
      if (items.length) output.push({type: 'list', ordered: tag === 'OL', items});
    } else if (tag === 'PRE') {
      const value = textOf(child);
      if (value.length <= 20000) output.push({type: 'code', language: 'plain', text: value});
    } else if (tag === 'HR') output.push({type: 'separator'});
    else if (tag === 'TABLE') {
      const rowNodes = [];
      const collectRows = current => {
        for (const item of children(current)) {
          if (item?.tagName === 'TR') rowNodes.push(item);
          else if (['THEAD', 'TBODY', 'TFOOT'].includes(item?.tagName)) collectRows(item);
        }
      };
      collectRows(child);
      const rows = rowNodes.slice(0, 20).map(row => children(row).filter(cell => cell?.tagName === 'TH' || cell?.tagName === 'TD').slice(0, 10).map(normalizedRuns));
      const width = rows[0]?.length ?? 0;
      if (width >= 2 && rows.every(row => row.length === width)) output.push({type: 'table', header: children(rowNodes[0]).some(cell => cell?.tagName === 'TH'), rows});
    } else convertContainer(child, output);
  }
}

/** Convert inert parsed HTML to typed article blocks. Returns null for ordinary inline-only paste. */
export function structuredHtml(root) {
  const blocks = [];
  convertContainer(root, blocks);
  const size = JSON.stringify(blocks).length;
  if (!blocks.length || blocks.length > 100 || size > 50000) return null;
  return blocks.length > 1 || blocks.some(block => block.type !== 'paragraph') ? blocks : null;
}
