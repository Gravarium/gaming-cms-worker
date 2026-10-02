import test from 'node:test';
import assert from 'node:assert/strict';
import { readPositions, writePosition, setResumeEnabled, bindJourney, playbackError } from '../../assets/video_playback_journey/journey.js';

const key = 'a'.repeat(64), other = 'b'.repeat(64), now = 2000000000000;
const storage = () => { const rows = new Map(); return { getItem: k => rows.get(k), setItem: (k, v) => rows.set(k, v), removeItem: k => rows.delete(k), rows }; };
class Control extends EventTarget { checked = false; hidden = false; textContent = ''; }
class Media extends EventTarget { currentTime = 0; duration = 300; error = null; seekable = { length: 1, start: () => 0, end: () => 300 }; }

test('resume is opt in, bounded, expires and stores only hashed keys, seconds and time', () => {
    const db = storage(); assert.equal(writePosition(db, key, 42, now), false);
    setResumeEnabled(db, true); writePosition(db, key, 42, now);
    assert.deepEqual(readPositions(db, now), { enabled: true, positions: [{ key, seconds: 42, at: now }] });
    assert.equal(readPositions(db, now + 31 * 86400000).positions.length, 0);
    assert.equal(writePosition(db, 'https://example.test/video.mp4', 42, now), false);
    assert.equal(writePosition(db, key, Infinity, now), false);
    for (let i = 0; i < 60; i++) writePosition(db, i.toString(16).padStart(64, '0'), i + 1, now);
    assert.equal(readPositions(db, now).positions.length, 50);
    setResumeEnabled(db, false); assert.equal(db.rows.size, 0);
});
test('malformed, future, duplicate and injected fields are not returned', () => {
    const db = storage(); db.setItem('cms-video-viewing-v1', JSON.stringify({ enabled: true, positions: [
        { key, seconds: 50, at: now, url: 'secret' }, { key, seconds: 10, at: now }, { key: other, seconds: 30, at: now + 1 }, { key: 'private-id', seconds: 20, at: now }
    ] }));
    assert.deepEqual(readPositions(db, now).positions, [{ key, seconds: 50, at: now }]);
});
test('explicit restore, erase, pause saving and listener cleanup', () => {
    const db = storage(); setResumeEnabled(db, true); writePosition(db, key, 90, now);
    const media = new Media(), consent = new Control(), restore = new Control(), erase = new Control(), status = new Control();
    const cleanup = bindJourney(media, { key, storage: db, consent, restore, erase, resumeStatus: status, now: () => now });
    assert.equal(media.currentTime, 0); assert.equal(restore.hidden, false); assert.equal(consent.checked, true);
    restore.dispatchEvent(new Event('click')); assert.equal(media.currentTime, 90);
    media.currentTime = 120; media.dispatchEvent(new Event('pause')); assert.equal(readPositions(db, now).positions[0].seconds, 120);
    erase.dispatchEvent(new Event('click')); assert.equal(readPositions(db, now).enabled, false);
    cleanup(); consent.checked = true; consent.dispatchEvent(new Event('change')); assert.equal(readPositions(db, now).enabled, false);
});
test('auto next requires consent, fires once, deletes completed position and never fires after disposal', () => {
    const db = storage(); setResumeEnabled(db, true); writePosition(db, key, 40, now);
    const media = new Media(), consent = new Control(), auto = new Control(); let submitted = 0;
    const cleanup = bindJourney(media, { key, storage: db, consent, auto, next: { requestSubmit: () => submitted++ }, now: () => now });
    media.dispatchEvent(new Event('ended')); assert.equal(submitted, 0);
    auto.checked = true; media.dispatchEvent(new Event('ended')); media.dispatchEvent(new Event('ended'));
    assert.equal(submitted, 1); assert.equal(readPositions(db, now).positions.length, 0);
    cleanup(); auto.checked = true; media.dispatchEvent(new Event('ended')); assert.equal(submitted, 1);
});
test('live streams and blocked storage do not persist or prevent playback', () => {
    const db = storage(); setResumeEnabled(db, true); const media = new Media(), consent = new Control(); media.duration = Infinity;
    const cleanup = bindJourney(media, { key, storage: db, consent, now: () => now }); media.currentTime = 50; media.dispatchEvent(new Event('pause'));
    assert.equal(readPositions(db, now).positions.length, 0); cleanup();
    const blocked = { getItem: () => { throw Error(); }, setItem: () => { throw Error(); }, removeItem: () => { throw Error(); } };
    assert.equal(setResumeEnabled(blocked, true), false); assert.equal(readPositions(blocked).enabled, false);
});
test('errors provide concrete recovery and Video.js uses its media interface', () => {
    const bus = new EventTarget(); let position = 0; const db = storage(); setResumeEnabled(db, true); writePosition(db, key, 60, now);
    const player = { on: (e, f) => bus.addEventListener(e, f), off: (e, f) => bus.removeEventListener(e, f), currentTime: n => n === undefined ? position : position = n,
        duration: () => 300, seekable: () => ({ length: 1, start: () => 0, end: () => 300 }), error: () => ({ code: 4 }) };
    const restore = new Control(), consent = new Control(), status = new Control();
    const cleanup = bindJourney(player, { key, storage: db, restore, consent, status, now: () => now });
    restore.dispatchEvent(new Event('click')); assert.equal(position, 60);
    bus.dispatchEvent(new Event('error')); assert.match(status.textContent, /CORS/); assert.match(playbackError(2), /Verbindung/); cleanup();
});
