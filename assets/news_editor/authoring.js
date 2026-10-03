const inline = {B: 'strong', STRONG: 'strong', I: 'em', EM: 'em', U: 'underline', S: 'strike', STRIKE: 'strike', DEL: 'strike', CODE: 'code'};
const breaks = new Set(['P', 'DIV', 'H1', 'H2', 'H3', 'H4', 'LI', 'BLOCKQUOTE', 'TR']);
const discard = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'MATH', 'TEMPLATE', 'FORM']);

export function safeEditorHref(value) {
  if (typeof value !== 'string' || !value || value.length > 2048 || /[\x00-\x20\x7f\\]/u.test(value) || value.startsWith('//')) return null;
  if (value.startsWith('/')) return value;
  if (!/^https?:\/\/[^/]+/i.test(value)) return null;
  try {
    const url = new URL(value);
    return ['http:', 'https:'].includes(url.protocol) && url.hostname && !url.username && !url.password ? value : null;
  } catch { return null; }
}

/** Read an inert DOMParser tree; return text and fixed marks, never untrusted elements. */
export function clipboardRuns(root) {
  const output = [];
  const add = (text, marks, href) => {
    if (!text) return;
    const last = output.at(-1);
    if (last && JSON.stringify(last.marks) === JSON.stringify(marks) && last.href === href && last.text.length + text.length <= 8000) { last.text += text; return; }
    output.push({text, marks: [...marks], ...(href ? {href} : {})});
  };
  function walk(node, marks = [], href) {
    if (node.nodeType === 3) { add(node.nodeValue ?? '', marks, href); return; }
    if (node.nodeType !== 1) return;
    const tag = node.tagName.toUpperCase();
    if (discard.has(tag)) return;
    if (tag === 'BR') { add('\n', marks, href); return; }
    const mark = tag === 'A' ? 'link' : inline[tag];
    const link = tag === 'A' ? safeEditorHref(node.getAttribute('href')) : null;
    const nextMarks = mark && (tag !== 'A' || link) && !marks.includes(mark) ? [...marks, mark] : marks;
    const nextHref = link || href;
    if (breaks.has(tag) && output.length && !output.at(-1).text.endsWith('\n')) add('\n', [], undefined);
    for (const child of node.childNodes) walk(child, nextMarks, nextHref);
    if (breaks.has(tag) && output.length && !output.at(-1).text.endsWith('\n')) add('\n', [], undefined);
  }
  for (const child of root.childNodes) walk(child);
  if (output.at(-1)?.text.endsWith('\n')) output.at(-1).text = output.at(-1).text.replace(/\n+$/u, '');
  return output.filter(run => run.text);
}

export function editList(items, action, index) {
  if (!Array.isArray(items) || !items.length || !Number.isInteger(index) || index < 0 || index >= items.length) return null;
  const next = structuredClone(items);
  if (action === 'insert' && items.length < 100) next.splice(index + 1, 0, [{text: '', marks: []}]);
  else if (action === 'remove' && items.length > 1) next.splice(index, 1);
  else if (action === 'up' && index > 0) [next[index - 1], next[index]] = [next[index], next[index - 1]];
  else if (action === 'down' && index < items.length - 1) [next[index], next[index + 1]] = [next[index + 1], next[index]];
  else return null;
  return next;
}

function replaceRuns(runs, needle, replacement) {
  const full = runs.map(run => run.text).join('');
  if (!full.includes(needle)) return {runs, count: 0};
  const positions = [];
  for (let index = full.indexOf(needle); index >= 0; index = full.indexOf(needle, index + needle.length)) positions.push(index);
  const ranges = [];
  let offset = 0;
  for (const run of runs) { ranges.push({start: offset, end: offset + run.text.length, run}); offset += run.text.length; }
  const result = [];
  const append = (start, end) => {
    for (const {start: from, end: to, run} of ranges) {
      const left = Math.max(start, from), right = Math.min(end, to);
      if (left < right) result.push({...run, text: run.text.slice(left - from, right - from)});
    }
  };
  let cursor = 0;
  for (const start of positions) {
    append(cursor, start);
    if (replacement) {
      const style = ranges.find(range => range.start <= start && start < range.end)?.run ?? {marks: []};
      result.push({...style, text: replacement});
    }
    cursor = start + needle.length;
  }
  append(cursor, full.length);
  return {runs: result.length ? result : [{text: '', marks: []}], count: positions.length};
}

export function replaceArticle(document, needle, replacement) {
  if (!needle || needle.length > 200 || replacement.length > 200 || /[\x00]/u.test(needle + replacement)) return null;
  const next = structuredClone(document);
  let count = 0;
  const apply = runs => { const result = replaceRuns(runs, needle, replacement); count += result.count; return result.runs; };
  for (const block of next.blocks) {
    if (block.content) block.content = apply(block.content);
    if (block.items) block.items = block.items.map(apply);
    if (block.rows) block.rows = block.rows.map(row => row.map(apply));
    for (const key of ['cite', 'caption', 'alt', 'text']) {
      if (typeof block[key] === 'string') { const before = block[key]; block[key] = before.split(needle).join(replacement); count += before.split(needle).length - 1; }
    }
  }
  return {document: next, count};
}

export function findArticleBlocks(document, needle) {
  if (!needle || needle.length > 200) return [];
  const found = [];
  document.blocks.forEach((block, index) => {
    const parts = [];
    if (block.content) parts.push(block.content.map(run => run.text).join(''));
    if (block.items) parts.push(...block.items.map(item => item.map(run => run.text).join('')));
    if (block.rows) parts.push(...block.rows.flatMap(row => row.map(cell => cell.map(run => run.text).join(''))));
    for (const key of ['cite', 'caption', 'alt', 'text']) if (typeof block[key] === 'string') parts.push(block[key]);
    if (parts.some(part => part.includes(needle))) found.push(index);
  });
  return found;
}
