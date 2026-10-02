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
  if (next.blocks.reduce((sum, item) => sum + runCount(item), 0) > MAX_RUNS) return null;
  if (new TextEncoder().encode('cms-rich:v2\n' + JSON.stringify(next)).length > MAX_BYTES) return null;
  return next;
}
