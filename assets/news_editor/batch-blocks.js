export function normalizeBlockSelection(selection, blockCount) {
  if (!Array.isArray(selection) || !Number.isInteger(blockCount) || blockCount < 1) return null;
  const normalized = [...new Set(selection)].sort((left, right) => left - right);
  if (!normalized.length || normalized.some(index => !Number.isInteger(index) || index < 0 || index >= blockCount)) return null;
  if (normalized.some((index, position) => position > 0 && index !== normalized[position - 1] + 1)) return null;
  return normalized;
}

export function applyBatchBlockOperation(document, selection, operation, maxBlocks = 100) {
  if (document?.version !== 2 || !Array.isArray(document.blocks) || !document.blocks.length || !Number.isInteger(maxBlocks) || maxBlocks < 1) return null;
  const selected = normalizeBlockSelection(selection, document.blocks.length);
  if (!selected) return null;
  const first = selected[0], last = selected.at(-1), count = selected.length;
  const next = JSON.parse(JSON.stringify(document));
  let nextSelection;
  if (operation === 'move-up') {
    if (first === 0) return null;
    const previous = next.blocks[first - 1];
    next.blocks.splice(first - 1, count + 1, ...next.blocks.slice(first, last + 1), previous);
    nextSelection = selected.map(index => index - 1);
  } else if (operation === 'move-down') {
    if (last === next.blocks.length - 1) return null;
    const following = next.blocks[last + 1];
    next.blocks.splice(first, count + 1, following, ...next.blocks.slice(first, last + 1));
    nextSelection = selected.map(index => index + 1);
  } else if (operation === 'duplicate') {
    if (next.blocks.length + count > maxBlocks) return null;
    const copies = JSON.parse(JSON.stringify(next.blocks.slice(first, last + 1)));
    next.blocks.splice(last + 1, 0, ...copies);
    nextSelection = selected.map(index => index + count);
  } else if (operation === 'delete') {
    if (count === next.blocks.length) return null;
    next.blocks.splice(first, count);
    nextSelection = [Math.min(first, next.blocks.length - 1)];
  } else return null;
  const labels = {'move-up': 'nach oben verschoben', 'move-down': 'nach unten verschoben', duplicate: 'dupliziert', delete: 'gelöscht'};
  return {
    document: next,
    selection: nextSelection,
    focusIndex: nextSelection[0],
    announcement: count + (count === 1 ? ' Block ' : ' Blöcke ') + labels[operation] + '.',
  };
}
