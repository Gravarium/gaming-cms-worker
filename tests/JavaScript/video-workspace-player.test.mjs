import test from 'node:test';
import assert from 'node:assert/strict';
import { attachPlayer } from '../../assets/video_workspace/player.js';

function video(mode = 'hls', native = '') {
    return { dataset: { source: 'https://cdn.example.test/live.m3u8', mode }, canPlayType: () => native, events: {}, addEventListener(name, fn) { this.events[name] = fn; }, removeAttribute() { delete this.src; }, load() {} };
}
test('direct MP4 uses own player without third-party library', async () => {
    const element = video('video');
    await attachPlayer(element, { loadHls: () => { throw Error('must not load'); } });
    assert.equal(element.src, element.dataset.source);
});
test('native HLS does not load JS fallback', async () => {
    const element = video('hls', 'probably');
    await attachPlayer(element, { loadHls: () => { throw Error('must not load'); } });
    assert.equal(element.src, element.dataset.source);
});
test('unsupported HLS reports useful fallback', async () => {
    const status = { textContent: '' };
    await attachPlayer(video(), { status, loadHls: async () => ({ default: { isSupported: () => false } }) });
    assert.match(status.textContent, /andere Quelle/);
});
test('HLS uses authorized manifest and releases resources', async () => {
    let instance;
    class Hls {
        static Events = { ERROR: 'error', MANIFEST_PARSED: 'manifest' };
        static isSupported() { return true; }
        constructor(options) { instance = this; this.options = options; this.handlers = {}; }
        on(name, handler) { this.handlers[name] = handler; }
        loadSource(url) { this.url = url; }
        attachMedia(media) { this.media = media; }
        destroy() { this.destroyed = true; }
    }
    const element = video();
    const cleanup = await attachPlayer(element, { loadHls: async () => ({ default: Hls }) });
    assert.equal(instance.url, element.dataset.source);
    assert.equal(instance.media, element);
    const xhr = { withCredentials: true }; instance.options.xhrSetup(xhr); assert.equal(xhr.withCredentials, false);
    cleanup(); assert.equal(instance.destroyed, true);
});
test('fatal stream failure is visible and stops playback', async () => {
    let handler; let destroyed = false;
    class Hls {
        static Events = { ERROR: 'error', MANIFEST_PARSED: 'manifest' }; static isSupported() { return true; }
        on(name, fn) { if (name === 'error') handler = fn; } loadSource() {} attachMedia() {} destroy() { destroyed = true; }
    }
    const status = { textContent: '' };
    await attachPlayer(video(), { status, loadHls: async () => ({ default: Hls }) });
    handler('error', { fatal: true }); assert.equal(destroyed, true); assert.match(status.textContent, /nicht erreichbar/);
});
