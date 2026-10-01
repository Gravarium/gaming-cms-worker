import test from 'node:test';
import assert from 'node:assert/strict';
import {parseVideoUrl} from '../../assets/news_editor/video-url.js';
import {EditorHistory} from '../../assets/news_editor/history.js';

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
