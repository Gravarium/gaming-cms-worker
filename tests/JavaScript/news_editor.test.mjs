import test from 'node:test';
import assert from 'node:assert/strict';
import {parseVideoUrl, videoPreviewUrl} from '../../assets/news_editor/video-url.js';
import {EditorHistory} from '../../assets/news_editor/history.js';
import {articleOutline, insertAfter, withinDocumentLimits} from '../../assets/news_editor/longform.js';
import {editTable} from '../../assets/news_editor/table-ops.js';
import {safeEditorHref, clipboardRuns, editList, replaceArticle, findArticleBlocks} from '../../assets/news_editor/authoring.js';
import {AutosaveState} from '../../assets/news_editor/autosave-state.js';
import {structuredMarkdown, importMarkdownArticle} from '../../assets/news_editor/markdown-paste.js';
import {articleMarkdown} from '../../assets/news_editor/markdown-io.js';
import {applyBlockOperation, resolveBlockShortcut} from '../../assets/news_editor/keyboard-blocks.js';

test('Markdown export and import preserve long-form text, links, tables and fenced code', () => {
  const document = {version: 2, blocks: [
    {type: 'heading', level: 2, content: [{text: 'Bericht', marks: []}]},
    {type: 'paragraph', content: [{text: 'Mehr ', marks: []}, {text: 'lesen', marks: ['link'], href: '/news'}]},
    {type: 'list', ordered: true, items: [[{text: 'Eins', marks: []}], [{text: 'Zwei', marks: ['strong']}]]},
    {type: 'table', header: true, rows: [[[ {text: 'Spiel', marks: []} ], [ {text: 'Wert', marks: []} ]], [[ {text: 'A', marks: []} ], [ {text: '8', marks: []} ]]]},
    {type: 'code', language: 'json', text: '{"fence":"```"}'},
    {type: 'separator'},
  ]};
  const markdown = articleMarkdown(document);
  assert.ok(markdown.includes('~~~json'));
  assert.ok(markdown.includes('[lesen](/news)'));
  assert.deepEqual(importMarkdownArticle(markdown), document.blocks);
  assert.deepEqual(importMarkdownArticle('Nur ein Absatz.'), [{type: 'paragraph', content: [{text: 'Nur ein Absatz.', marks: []}]}]);
});

test('Markdown import bounds input and neutralizes untrusted links and HTML', () => {
  assert.equal(importMarkdownArticle('## ' + 'x'.repeat(50001)), null);
  assert.equal(importMarkdownArticle('```php\nunclosed'), null);
  const blocks = importMarkdownArticle('## <script>alert(1)</script>\n\n[Link](javascript:alert(1))');
  assert.equal(blocks[0].content[0].text, '<script>alert(1)</script>');
  assert.deepEqual(blocks[1].content, [{text: 'Link', marks: []}]);
  assert.equal(articleMarkdown({version: 3, blocks: []}), null);
  assert.match(articleMarkdown({version: 2, blocks: [{type: 'media', assetId: 17, alt: 'Titelbild', caption: ''}]}), /CMS-Mediathek #17/);
});

test('keyboard block shortcuts require an unambiguous primary plus shift chord', () => {
  const chord = key => ({key, ctrlKey: true, metaKey: false, shiftKey: true, altKey: false});
  assert.equal(resolveBlockShortcut(chord('Enter')), 'insert-after');
  assert.equal(resolveBlockShortcut(chord('D')), 'duplicate');
  assert.equal(resolveBlockShortcut(chord('ArrowUp')), 'move-up');
  assert.equal(resolveBlockShortcut(chord('ArrowDown')), 'move-down');
  assert.equal(resolveBlockShortcut(chord('Backspace')), 'remove');
  assert.equal(resolveBlockShortcut({...chord('D'), shiftKey: false}), null);
  assert.equal(resolveBlockShortcut({...chord('D'), altKey: true}), null);
  assert.equal(resolveBlockShortcut({...chord('D'), metaKey: true}), null);
  assert.equal(resolveBlockShortcut(chord('x')), null);
});

test('keyboard block operations are immutable, bounded and retain the focused block', () => {
  const paragraph = text => ({type: 'paragraph', content: [{text, marks: []}]});
  const source = {version: 2, blocks: [paragraph('A'), paragraph('<B>')]};
  const duplicate = applyBlockOperation(source, 'duplicate', 1);
  assert.deepEqual(duplicate.document.blocks.map(block => block.content[0].text), ['A', '<B>', '<B>']);
  assert.equal(duplicate.index, 2);
  assert.equal(duplicate.announcement, 'Block dupliziert. Position 3 von 3.');
  duplicate.document.blocks[2].content[0].text = 'changed';
  assert.equal(source.blocks[1].content[0].text, '<B>');

  const moved = applyBlockOperation(source, 'move-down', 0);
  assert.deepEqual(moved.document.blocks.map(block => block.content[0].text), ['<B>', 'A']);
  assert.equal(moved.index, 1);
  assert.equal(applyBlockOperation(source, 'move-up', 0), null);
  assert.equal(applyBlockOperation(source, 'move-down', 1), null);

  const inserted = applyBlockOperation(source, 'insert-after', 0);
  assert.deepEqual(inserted.document.blocks[1], paragraph(''));
  assert.equal(applyBlockOperation(source, 'duplicate', 0, 2), null);

  const removed = applyBlockOperation(source, 'remove', 1);
  assert.deepEqual(removed.document.blocks, [paragraph('A')]);
  assert.equal(removed.index, 0);
  assert.equal(applyBlockOperation({version: 2, blocks: [paragraph('only')]}, 'remove', 0), null);
  assert.equal(applyBlockOperation(source, 'unknown', 0), null);
  assert.equal(applyBlockOperation(source, 'duplicate', 4), null);
});

test('structured Markdown paste makes safe typed article blocks', () => {
  const blocks = structuredMarkdown('# Intro **stark**\n\n- Eins\n- [Zwei](https://example.test/x)\n\n> Zitat\n\nA | B\n--- | ---\nX | Y\n\n```json\n{"x":1}\n```\n\n---');
  assert.deepEqual(blocks.map(block => block.type), ['heading', 'list', 'quote', 'table', 'code', 'separator']);
  assert.equal(blocks[0].level, 2);
  assert.deepEqual(blocks[0].content[1], {text: 'stark', marks: ['strong']});
  assert.deepEqual(blocks[1].items[1], [{text: 'Zwei', marks: ['link'], href: 'https://example.test/x'}]);
  assert.equal(blocks[3].rows[1][1][0].text, 'Y');
  assert.equal(blocks[4].text, '{"x":1}');
  assert.equal(withinDocumentLimits({version: 2, blocks}), true);
});

test('structured paste keeps active HTML inert and discards unsafe link targets', () => {
  const blocks = structuredMarkdown('## <img src=x onerror=1>\n\n- [unsafe](javascript:alert(1))\n- `code`');
  assert.equal(blocks[0].content[0].text, '<img src=x onerror=1>');
  assert.deepEqual(blocks[1].items[0], [{text: 'unsafe', marks: []}]);
  assert.deepEqual(blocks[1].items[1], [{text: 'code', marks: ['code']}]);
  assert.equal(structuredMarkdown('plain paragraph only'), null);
  assert.equal(structuredMarkdown('```js\nnot closed'), null);
  assert.equal(structuredMarkdown('## ' + 'x'.repeat(50001)), null);
});

test('news editor extracts only fixed-provider video IDs', () => {
  assert.deepEqual(parseVideoUrl('https://www.youtube.com/watch?v=abc123_DEF0'), {provider: 'youtube', videoId: 'abc123_DEF0'});
  assert.deepEqual(parseVideoUrl('https://youtu.be/abc123_DEF0?t=2'), {provider: 'youtube', videoId: 'abc123_DEF0'});
  assert.deepEqual(parseVideoUrl('https://vimeo.com/12345'), {provider: 'vimeo', videoId: '12345'});
  for (const url of [
    'javascript:alert(1)',
    'http://youtu.be/abc123_DEF0',
    'https://youtube.com.evil.test/watch?v=abc123_DEF0',
    'https://user@youtube.com/watch?v=abc123_DEF0',
    'https://vimeo.com/not-an-id',
  ]) assert.equal(parseVideoUrl(url), null, url);
  assert.equal(videoPreviewUrl('youtube', 'abc123_DEF0'), 'https://www.youtube-nocookie.com/embed/abc123_DEF0');
  assert.equal(videoPreviewUrl('vimeo', '12345'), 'https://player.vimeo.com/video/12345');
  for (const [provider, id] of [['youtube', '<script>'], ['vimeo', '123/x'], ['evil', '12345'], ['youtube', 'abc123_DEF0?autoplay=1']]) assert.equal(videoPreviewUrl(provider, id), null);
});

test('news editor history groups typing but keeps structural steps and drops stale redo', () => {
  const history = new EditorHistory('original', 4);
  history.record('first letter', {input: true, time: 1000});
  history.record('word', {input: true, time: 1200});
  history.record('word plus table', {time: 1300});
  assert.equal(history.undo(), 'word');
  assert.equal(history.undo(), 'original');
  assert.equal(history.redo(), 'word');
  history.record('changed heading', {time: 2000});
  assert.equal(history.redo(), null);
  assert.equal(history.undo(), 'word');
  assert.equal(history.undo(), 'original');
  assert.equal(history.undo(), null);
});

test('news editor history discards only the oldest step at its limit', () => {
  const history = new EditorHistory('one', 3);
  history.record('two'); history.record('three'); history.record('four');
  assert.equal(history.undo(), 'three');
  assert.equal(history.undo(), 'two');
  assert.equal(history.undo(), null);
});

test('outline tracks heading order and returns plain text from rich runs', () => {
  const blocks = [{type: 'paragraph', content: [{text: 'intro', marks: []}]}, {type: 'heading', level: 2, content: [{text: 'Start ', marks: []}, {text: '<safe>', marks: ['strong']}]}, {type: 'heading', level: 3, content: [{text: '', marks: []}]}];
  assert.deepEqual(articleOutline(blocks), [{index: 1, level: 2, title: 'Start <safe>'}, {index: 2, level: 3, title: 'Überschrift ohne Text'}]);
});

test('contextual insertion preserves original and rejects document bounds', () => {
  const heading = {type: 'heading', level: 2, content: [{text: '<script>', marks: []}]};
  const source = {version: 2, blocks: [heading, {type: 'separator'}]};
  const next = insertAfter(source, heading, 0);
  assert.deepEqual(next.blocks, [heading, heading, {type: 'separator'}]);
  next.blocks[1].content[0].text = 'copy';
  assert.equal(source.blocks[0].content[0].text, '<script>');
  assert.equal(insertAfter(source, heading, 2), null);
  assert.equal(insertAfter({version: 2, blocks: Array(100).fill(heading)}, heading, 0), null);
  assert.equal(insertAfter({version: 2, blocks: [{type: 'code', language: 'plain', text: 'x'.repeat(59900)}]}, heading, 0), null);
  const runs = Array(500).fill({text: 'x', marks: []});
  assert.equal(insertAfter({version: 2, blocks: [{type: 'paragraph', content: runs}]}, heading, 0), null);
});

test('table operations modify selected positions and keep rectangular boundaries', () => {
  const cell = text => [{text, marks: []}];
  const rows = [[cell('A'), cell('<B>')], [cell('C'), cell('D')]];
  const withRow = editTable(rows, 'insert-row', 0, 1);
  assert.deepEqual(withRow[1], [cell(''), cell('')]);
  assert.equal(withRow[2][0][0].text, 'C');
  const withColumn = editTable(rows, 'insert-column', 1, 0);
  assert.deepEqual(withColumn[0].map(c => c[0].text), ['A', '', '<B>']);
  assert.deepEqual(editTable(rows, 'remove-row', 0, 0), [rows[1]]);
  assert.deepEqual(editTable(rows, 'remove-column', 0, 1), [[cell('A')], [cell('C')]]);
  assert.equal(rows[0][1][0].text, '<B>');
  assert.equal(editTable([rows[0]], 'remove-row', 0, 0), null);
  assert.equal(editTable([[cell('A')]], 'remove-column', 0, 0), null);
  assert.equal(editTable(rows, 'insert-column', 0, 3), null);
  assert.equal(editTable(Array(20).fill(rows[0]), 'insert-row', 0, 0), null);
  assert.equal(editTable([Array(10).fill(cell('x'))], 'insert-column', 0, 0), null);
  assert.equal(withinDocumentLimits({version: 2, blocks: [{type: 'table', header: false, rows: [Array(501).fill(cell('x'))]}]}), false);
});

test('rich clipboard keeps fixed formatting and discards active markup and unsafe links', () => {
  const text = nodeValue => ({nodeType: 3, nodeValue});
  const tag = (tagName, childNodes = [], href = null) => ({nodeType: 1, tagName, childNodes, getAttribute: key => key === 'href' ? href : null});
  const root = {childNodes: [tag('P', [text('A '), tag('STRONG', [text('bold')]), tag('A', [text(' safe')], '/news'), tag('SCRIPT', [text('evil()')])]), tag('P', [tag('A', [text(' plain')], 'javascript:alert(1)'), tag('IMG', [], 'onerror=evil')])]};
  assert.deepEqual(clipboardRuns(root), [
    {text: 'A ', marks: []}, {text: 'bold', marks: ['strong']},
    {text: ' safe', marks: ['link'], href: '/news'}, {text: '\n plain', marks: []},
  ]);
  for (const unsafe of ['javascript:alert(1)', '//evil.test', '/\\evil', 'https://user@evil.test/', 'https://evil.test/\nnext']) assert.equal(safeEditorHref(unsafe), null);
  assert.equal(safeEditorHref('https://example.test/news'), 'https://example.test/news');
});

test('list operations are bounded, preserve content, and reorder the selected item', () => {
  const items = [[{text: 'A', marks: []}], [{text: '<B>', marks: ['strong']}]];
  assert.deepEqual(editList(items, 'down', 0), [items[1], items[0]]);
  assert.deepEqual(editList(items, 'insert', 0)[1], [{text: '', marks: []}]);
  assert.deepEqual(editList(items, 'remove', 1), [items[0]]);
  assert.equal(editList([items[0]], 'remove', 0), null);
  assert.equal(editList(Array(100).fill(items[0]), 'insert', 0), null);
  assert.equal(items[1][0].text, '<B>');
});

test('find and replace spans rich runs while keeping surrounding marks and escaping as text', () => {
  const document = {version: 2, blocks: [
    {type: 'paragraph', content: [{text: 'A bo', marks: []}, {text: 'ld word', marks: ['strong']}]},
    {type: 'list', ordered: false, items: [[{text: 'bold', marks: []}]]},
    {type: 'table', header: true, rows: [[[{text: 'bold', marks: []}]]]},
  ]};
  const result = replaceArticle(document, 'bold', '<safe>');
  assert.equal(result.count, 3);
  assert.deepEqual(findArticleBlocks(document, 'bold'), [0, 1, 2]);
  assert.equal(result.document.blocks[0].content.map(run => run.text).join(''), 'A <safe> word');
  assert.equal(result.document.blocks[0].content[1].text, '<safe>');
  assert.deepEqual(result.document.blocks[0].content[1].marks, []);
  assert.equal(document.blocks[0].content[0].text, 'A bo');
  assert.equal(replaceArticle(document, '', 'x'), null);
  assert.equal(replaceArticle(document, 'a', 'x'.repeat(201)), null);
});

test('autosave acknowledges only the sent snapshot and blocks writes after a conflict', () => {
  const state = new AutosaveState('saved');
  assert.equal(state.begin('saved'), null);
  assert.equal(state.begin('first'), 'first');
  assert.equal(state.begin('second'), null);
  assert.equal(state.success('other'), false);
  assert.equal(state.success('first'), true);
  assert.equal(state.isDirty('second'), true);
  assert.equal(state.begin('second'), 'second');
  state.failure(true);
  assert.equal(state.begin('third'), null);
  assert.equal(state.isDirty('third'), true);
});
