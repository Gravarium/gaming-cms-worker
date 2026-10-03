const MAX_BYTES = 60000;
const MAX_BLOCKS = 100;
const MAX_RUNS = 500;

export function articleOutline(blocks) {
  return blocks.flatMap((block, index) => block.type === 'heading' ? [{
    index,
    level: block.level,
    title: block.content.map(run => run.text).join('').trim() || 'Überschrift ohne Text',
  }] : []);
}

function runCount(block) {
  if (block.content) return block.content.length;
  if (block.items) return block.items.reduce((sum, item) => sum + item.length, 0);
  if (block.rows) return block.rows.reduce((sum, row) => sum + row.reduce((total, cell) => total + cell.length, 0), 0);
  return 0;
}

/** Insert a copy while respecting the server's document-wide limits. */
export function insertAfter(document, block, index) {
  if (document.version !== 2 || !Array.isArray(document.blocks) || document.blocks.length >= MAX_BLOCKS || !Number.isInteger(index) || index < -1 || index >= document.blocks.length) return null;
  const next = {version: 2, blocks: [...document.blocks]};
  next.blocks.splice(index + 1, 0, structuredClone(block));
  return withinDocumentLimits(next) ? next : null;
}

export function withinDocumentLimits(document) {
  return document.version === 2 && Array.isArray(document.blocks) && document.blocks.length > 0 && document.blocks.length <= MAX_BLOCKS
    && document.blocks.reduce((sum, item) => sum + runCount(item), 0) <= MAX_RUNS
    && document.blocks.every(block => {
      const validRuns = runs => Array.isArray(runs) && runs.length > 0 && runs.length <= 100 && runs.every(run => typeof run.text === 'string' && run.text.length <= 8000);
      if (block.content && !validRuns(block.content)) return false;
      if (block.items && (block.items.length < 1 || block.items.length > 100 || !block.items.every(validRuns))) return false;
      if (block.rows && (block.rows.length < 1 || block.rows.length > 20 || block.rows[0].length < 1 || block.rows[0].length > 10 || !block.rows.every(row => row.length === block.rows[0].length && row.every(validRuns)))) return false;
      if (typeof block.text === 'string' && block.text.length > 12000) return false;
      if (typeof block.cite === 'string' && block.cite.length > 300) return false;
      if (typeof block.alt === 'string' && block.alt.length > 300) return false;
      return !(typeof block.caption === 'string' && block.caption.length > 500);
    })
    && new TextEncoder().encode('cms-rich:v2\n' + JSON.stringify(document)).length <= MAX_BYTES;
}
