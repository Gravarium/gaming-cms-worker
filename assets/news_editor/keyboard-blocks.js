const SHORTCUTS = new Map([
  ['enter', 'insert-after'],
  ['d', 'duplicate'],
  ['arrowup', 'move-up'],
  ['arrowdown', 'move-down'],
  ['backspace', 'remove'],
]);

export function resolveBlockShortcut(event) {
  const primary = Boolean(event.ctrlKey) !== Boolean(event.metaKey);
  if (!primary || !event.shiftKey || event.altKey) return null;
  return SHORTCUTS.get(String(event.key ?? '').toLowerCase()) ?? null;
}

export function applyBlockOperation(document, command, index, maxBlocks = 100) {
  if (!document || document.version !== 2 || !Array.isArray(document.blocks)) return null;
  if (!Number.isInteger(index) || index < 0 || index >= document.blocks.length) return null;

  const blocks = JSON.parse(JSON.stringify(document.blocks));
  let nextIndex = index;

  if (command === 'insert-after') {
    if (blocks.length >= maxBlocks) return null;
    blocks.splice(index + 1, 0, {type: 'paragraph', content: [{text: '', marks: []}]});
    nextIndex = index + 1;
  } else if (command === 'duplicate') {
    if (blocks.length >= maxBlocks) return null;
    blocks.splice(index + 1, 0, JSON.parse(JSON.stringify(blocks[index])));
    nextIndex = index + 1;
  } else if (command === 'move-up') {
    if (index === 0) return null;
    [blocks[index - 1], blocks[index]] = [blocks[index], blocks[index - 1]];
    nextIndex = index - 1;
  } else if (command === 'move-down') {
    if (index === blocks.length - 1) return null;
    [blocks[index], blocks[index + 1]] = [blocks[index + 1], blocks[index]];
    nextIndex = index + 1;
  } else if (command === 'remove') {
    if (blocks.length === 1) return null;
    blocks.splice(index, 1);
    nextIndex = Math.min(index, blocks.length - 1);
  } else {
    return null;
  }

  const labels = {
    'insert-after': 'Neuer Absatz eingefügt',
    duplicate: 'Block dupliziert',
    'move-up': 'Block nach oben verschoben',
    'move-down': 'Block nach unten verschoben',
    remove: 'Block entfernt',
  };

  return {
    document: {...document, blocks},
    index: nextIndex,
    announcement: `${labels[command]}. Position ${nextIndex + 1} von ${blocks.length}.`,
  };
}
