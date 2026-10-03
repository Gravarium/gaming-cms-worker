import test from 'node:test';
import assert from 'node:assert/strict';
import { requestPreviewFullscreen } from '../../assets/video_player_branding/preview.js';

test('fullscreen uses the preview frame so its own badge stays visible', async () => {
    let called = 0;
    assert.equal(await requestPreviewFullscreen({ requestFullscreen: () => { called++; return Promise.resolve(); } }), true);
    assert.equal(called, 1);
    assert.equal(await requestPreviewFullscreen(null), false);
    assert.equal(await requestPreviewFullscreen({ requestFullscreen: () => Promise.reject(new Error('denied')) }), false);
});
