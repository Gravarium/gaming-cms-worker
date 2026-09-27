import test from 'node:test';
import assert from 'node:assert/strict';
import {applyMediaSelection, normalizeMediaPickerItem} from '../../assets/content_media_picker.js';

const item = overrides => ({
    id: 42,
    title: 'Spielgrafik',
    originalName: 'spielgrafik.webp',
    altText: 'Eine Karte',
    url: '/uploads/media/content/spielgrafik.webp',
    ...overrides,
});

test('selecting a library result fills its asset ID and leaves editor text intact', () => {
    const block = {type: 'media', assetId: 0, alt: '', caption: 'Karte im Spiel'};

    assert.equal(applyMediaSelection(block, item()), block);
    assert.deepEqual(block, {
        type: 'media',
        assetId: 42,
        alt: 'Eine Karte',
        caption: 'Karte im Spiel',
    });
});

test('selection keeps a custom alt text and accepts a safe HTTPS library image', () => {
    const block = {type: 'media', assetId: 8, alt: 'Eigener Alternativtext', caption: ''};
    const remote = item({url: 'https://cdn.example.test/media/map.webp', altText: 'Standard'});

    applyMediaSelection(block, remote);

    assert.equal(block.assetId, 42);
    assert.equal(block.alt, 'Eigener Alternativtext');
    assert.equal(normalizeMediaPickerItem(remote)?.url, remote.url);
});

test('invalid IDs, media URLs, and non-media blocks cannot become selections', () => {
    const block = {type: 'media', assetId: 0, alt: '', caption: ''};

    for (const invalid of [
        item({id: 0}),
        item({id: Number.MAX_SAFE_INTEGER + 1}),
        item({url: 'javascript:alert(1)'}),
        item({url: 'http://cdn.example.test/image.webp'}),
        item({url: '/uploads/media/../private.webp'}),
        item({url: '/outside/image.webp'}),
    ]) {
        assert.throws(() => applyMediaSelection(block, invalid), TypeError);
        assert.equal(block.assetId, 0);
    }

    assert.throws(() => applyMediaSelection({type: 'text', text: 'Hi'}, item()), TypeError);
});
