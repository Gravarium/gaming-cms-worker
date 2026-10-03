import test from 'node:test';
import assert from 'node:assert/strict';
import { bindProviderDocumentation } from '../../assets/video_workspace/provider-docs.js';

class Select {
    constructor(options, value) { this.options = options; this.value = value; this.listeners = new Map(); }
    addEventListener(type, callback) { this.listeners.set(type, callback); }
    removeEventListener(type, callback) { if (this.listeners.get(type) === callback) this.listeners.delete(type); }
    change(value) { this.value = value; this.listeners.get('change')?.(); }
}
function link() {
    return { href: '', textContent: '', hidden: true, removeAttribute(name) { if (name === 'href') this.href = ''; } };
}
const option = (value, label, documentation) => ({ value, textContent: label, dataset: { providerLabel: label, documentation } });

test('shows the selected provider documentation and updates after selection', () => {
    const select = new Select([
        option('youtube', 'YouTube', 'https://developers.google.com/youtube/player_parameters'),
        option('kinescope', 'Kinescope', 'https://docs.kinescope.com/video-player/embedding/'),
    ], 'youtube');
    const anchor = link();
    const dispose = bindProviderDocumentation(select, anchor);
    assert.equal(anchor.hidden, false);
    assert.equal(anchor.href, 'https://developers.google.com/youtube/player_parameters');
    assert.equal(anchor.textContent, 'YouTube · Dokumentation');
    select.change('kinescope');
    assert.equal(anchor.href, 'https://docs.kinescope.com/video-player/embedding/');
    assert.equal(anchor.textContent, 'Kinescope · Dokumentation');
    dispose();
});

test('hides and clears stale links for non-HTTPS or credential-bearing documentation', () => {
    const select = new Select([
        option('safe', 'Safe provider', 'https://docs.example.test/guide'),
        option('http', 'HTTP provider', 'http://docs.example.test/guide'),
        option('script', 'Script provider', 'javascript:alert(1)'),
        option('credentials', 'Credentials provider', 'https://user:pass@docs.example.test/guide'),
    ], 'safe');
    const anchor = link();
    bindProviderDocumentation(select, anchor);
    assert.equal(anchor.hidden, false);
    for (const value of ['http', 'script', 'credentials', 'missing']) {
        select.change(value);
        assert.equal(anchor.hidden, true);
        assert.equal(anchor.href, '');
    }
});

test('cleanup removes the change listener', () => {
    const select = new Select([option('one', 'One', 'https://docs.example.test/one')], 'one');
    const anchor = link();
    const dispose = bindProviderDocumentation(select, anchor);
    dispose();
    select.value = 'missing';
    select.change('missing');
    assert.equal(anchor.hidden, false);
    assert.equal(anchor.href, 'https://docs.example.test/one');
});
