import test from 'node:test';
import assert from 'node:assert/strict';
import { attachChoice, preferEngine, rememberEngine } from '../../assets/video_player_choice/player.js';

function video(engine = 'videojs') {
    const listeners = new Map();
    return { dataset: { engine, mode: 'hls', source: 'https://cdn.example.test/live.m3u8', start: '30', speed: '1.5', muted: '1', loop: '1', autoplay: '0' },
        classList: { add() {}, remove() {} }, addEventListener: (key, handler) => listeners.set(key, handler),
        removeEventListener: key => listeners.delete(key), listeners, play: () => Promise.resolve() };
}

test('Video.js receives the authorized source and options and disposes exactly once', async () => {
    const node = video(), events = new Map(); let settings, disposed = 0, speed, at;
    const cleanup = await attachChoice(node, { loadLibrary: async () => (_element, options) => {
        settings = options;
        return { on: (event, handler) => events.set(event, handler), dispose: () => disposed++, playbackRate: value => { speed = value; }, currentTime: value => { at = value; } };
    } });
    assert.equal(settings.sources[0].type, 'application/x-mpegURL');
    assert.equal(settings.sources[0].src, node.dataset.source);
    assert.equal(settings.html5.vhs.withCredentials, false);
    assert.equal(settings.muted, true); events.get('loadedmetadata')();
    assert.equal(speed, 1.5); assert.equal(at, 30);
    cleanup(); cleanup(); assert.equal(disposed, 1);
});

test('cancelled navigation cannot initialize a late-loaded library', async () => {
    const node = video(); let current = true, resolveLibrary, initialized = 0;
    const result = attachChoice(node, { isCurrent: () => current, loadLibrary: () => new Promise(resolve => { resolveLibrary = resolve; }) });
    current = false; resolveLibrary(() => { initialized++; });
    (await result)(); assert.equal(initialized, 0);
});

test('library failure falls back to native HLS player and removes metadata handlers on stop', async () => {
    const node = video(), status = { textContent: '' }; let native = 0, cleaned = 0;
    const cleanup = await attachChoice(node, { status, loadLibrary: async () => { throw new Error('offline'); }, nativePlayer: async () => { native++; return () => cleaned++; } });
    assert.equal(native, 1); assert.match(status.textContent, /Browser-Player/);
    node.listeners.get('loadedmetadata')(); assert.equal(node.currentTime, 30); assert.equal(node.playbackRate, 1.5);
    cleanup(); assert.equal(cleaned, 1); assert.equal(node.listeners.size, 0);
});

test('a stored engine is used only if compatible and blocked storage does not stop playback', () => {
    const select = { value: 'embed', options: [{ value: 'embed' }, { value: 'youtube' }] };
    preferEngine(select, { getItem: () => 'videojs' }); assert.equal(select.value, 'embed');
    preferEngine(select, { getItem: () => 'youtube' }); assert.equal(select.value, 'youtube');
    preferEngine(select, { getItem() { throw new Error('disabled'); } });
    let stored; rememberEngine('videojs', { setItem: (_key, value) => { stored = value; } }); assert.equal(stored, 'videojs');
    rememberEngine('arbitrary-script', { setItem() { throw new Error('must not store'); } });
});

test('unsupported engines never load any library or source', async () => {
    const node = video('youtube');
    (await attachChoice(node, { loadLibrary: () => { throw new Error('must not load'); }, nativePlayer: () => { throw new Error('must not load'); } }))();
});
