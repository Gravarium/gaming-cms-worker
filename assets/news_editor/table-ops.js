const emptyCell = () => [{text: '', marks: []}];

/** A rectangular, bounded table edit. Returns null if the requested edit is unsafe. */
export function editTable(rows, operation, rowIndex, columnIndex) {
  if (!Array.isArray(rows) || !rows.length || !rows[0]?.length || !rows.every(row => Array.isArray(row) && row.length === rows[0].length)) return null;
  const height = rows.length, width = rows[0].length;
  const next = structuredClone(rows);
  if (operation === 'insert-row') {
    if (!Number.isInteger(rowIndex) || rowIndex < 0 || rowIndex >= height || height >= 20) return null;
    next.splice(rowIndex + 1, 0, Array.from({length: width}, emptyCell));
  } else if (operation === 'remove-row') {
    if (!Number.isInteger(rowIndex) || rowIndex < 0 || rowIndex >= height || height <= 1) return null;
    next.splice(rowIndex, 1);
  } else if (operation === 'insert-column') {
    if (!Number.isInteger(columnIndex) || columnIndex < 0 || columnIndex >= width || width >= 10) return null;
    for (const row of next) row.splice(columnIndex + 1, 0, emptyCell());
  } else if (operation === 'remove-column') {
    if (!Number.isInteger(columnIndex) || columnIndex < 0 || columnIndex >= width || width <= 1) return null;
    for (const row of next) row.splice(columnIndex, 1);
  } else return null;
  return next;
}
