import {insertAfter} from './longform.js';

const TYPES = new Set(['paragraph', 'heading', 'quote', 'list', 'table', 'callout', 'code', 'separator', 'media', 'video']);

export function normalizeSnippetItems(payload) {
  if (!payload || !Array.isArray(payload.items) || payload.items.length > 30) return [];
  return payload.items.flatMap(item => {
    if (!Number.isInteger(item?.id) || item.id < 1 || typeof item.label !== 'string' || !item.label.trim() || item.label.length > 80
      || !item.block || typeof item.block !== 'object' || Array.isArray(item.block) || !TYPES.has(item.block.type)) return [];
    return [{id: item.id, label: item.label.trim(), block: structuredClone(item.block), updatedAt: typeof item.updatedAt === 'string' ? item.updatedAt : ''}];
  });
}

export function insertSnippet(document, snippet, afterIndex) {
  if (!snippet || !Number.isInteger(snippet.id) || snippet.id < 1 || !snippet.block || !TYPES.has(snippet.block.type)) return null;
  const next = insertAfter(document, snippet.block, afterIndex);
  return next ? {document: next, index: afterIndex + 1} : null;
}
