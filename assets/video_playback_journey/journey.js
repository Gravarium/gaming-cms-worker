import { attachChoice } from '../video_player_choice/player.js';

const STORAGE_KEY = 'cms-video-viewing-v1';
const TTL = 30 * 24 * 60 * 60 * 1000;
const validKey = key => /^[a-f0-9]{64}$/.test(key || '');

export function readPositions(storage, now = Date.now()) {
    try {
        const value = JSON.parse(storage?.getItem(STORAGE_KEY) || 'null');
        if (value?.enabled !== true || !Array.isArray(value.positions)) return { enabled: false, positions: [] };
        const seen = new Set();
        const positions = value.positions.filter(row => {
            if (!validKey(row?.key) || seen.has(row.key) || !Number.isFinite(row.seconds) || row.seconds < 1 || row.seconds > 86400
                || !Number.isFinite(row.at) || row.at > now || row.at < now - TTL) return false;
            seen.add(row.key); return true;
        }).slice(-50).map(({ key, seconds, at }) => ({ key, seconds, at }));
        return { enabled: true, positions };
    } catch { return { enabled: false, positions: [] }; }
}

export function writePosition(storage, key, seconds, now = Date.now(), completed = false) {
    if (!validKey(key) || !Number.isFinite(seconds) || seconds < 0 || seconds > 86400) return false;
    const state = readPositions(storage, now);
    if (!state.enabled) return false;
    state.positions = state.positions.filter(row => row.key !== key);
    if (!completed && seconds >= 1) state.positions.push({ key, seconds: Math.floor(seconds), at: now });
    state.positions = state.positions.slice(-50);
    try { storage.setItem(STORAGE_KEY, JSON.stringify(state)); return true; } catch { return false; }
}

export function setResumeEnabled(storage, enabled) {
    try {
        if (enabled) storage.setItem(STORAGE_KEY, JSON.stringify({ ...readPositions(storage), enabled: true }));
        else storage.removeItem(STORAGE_KEY);
        return true;
    } catch { return false; }
}

export function playbackError(code) {
    return ({
        1: 'Wiedergabe abgebrochen. Bitte erneut Play drücken.',
        2: 'Verbindung zur Quelle fehlgeschlagen. Internetverbindung und Quellfreigabe prüfen oder eine andere Quelle wählen.',
        3: 'Das Video konnte nicht dekodiert werden. Bitte einen anderen Player oder eine andere Quelle wählen.',
        4: 'Format oder Quelle nicht unterstützt. Bei Streams auch die CORS-Freigabe des Anbieters prüfen.'
    })[code] || 'Wiedergabe nicht möglich. Bitte einen anderen Player oder eine andere Quelle wählen.';
}

// The same interface covers native media and Video.js without reading provider iframe state.
export function bindJourney(media, { key = '', storage = null, consent = null, restore = null, erase = null,
    status = null, resumeStatus = null, next = null, auto = null, now = () => Date.now() } = {}) {
    let disposed = false, lastSaved = -Infinity;
    const listeners = [];
    const time = () => typeof media.currentTime === 'function' ? media.currentTime() : media.currentTime;
    const duration = () => typeof media.duration === 'function' ? media.duration() : media.duration;
    const listen = (target, event, handler) => {
        if (!target) return;
        if (typeof target.on === 'function') { target.on(event, handler); listeners.push(() => target.off(event, handler)); }
        else { target.addEventListener(event, handler); listeners.push(() => target.removeEventListener(event, handler)); }
    };
    const reportResume = message => { if (resumeStatus) resumeStatus.textContent = message; };
    const refresh = () => {
        const state = readPositions(storage, now());
        if (consent) consent.checked = state.enabled;
        const row = state.positions.find(item => item.key === key);
        if (restore) { restore.hidden = !row; restore.textContent = row ? `Bei ${Math.floor(row.seconds / 60)}:${String(row.seconds % 60).padStart(2, '0')} weiterschauen` : 'Gespeicherte Position laden'; }
    };
    const save = () => {
        if (disposed || !consent?.checked || !validKey(key)) return;
        const end = duration(), seconds = time();
        // Live/unseekable streams have no reliable playback position.
        if (!Number.isFinite(end) || end <= 0 || !Number.isFinite(seconds)) return;
        if (!writePosition(storage, key, seconds, now(), seconds >= end - 2)) reportResume('Speichern ist in diesem Browser nicht verfügbar.');
    };
    listen(consent, 'change', () => {
        if (!setResumeEnabled(storage, consent.checked)) { consent.checked = false; reportResume('Speichern ist in diesem Browser nicht verfügbar.'); }
        else reportResume(consent.checked ? 'Positionen werden nur auf diesem Gerät gespeichert.' : 'Speichern ausgeschaltet und alle Positionen gelöscht.');
        refresh();
    });
    listen(erase, 'click', () => { setResumeEnabled(storage, false); refresh(); reportResume('Alle gespeicherten Positionen wurden gelöscht.'); });
    listen(restore, 'click', () => {
        const row = readPositions(storage, now()).positions.find(item => item.key === key);
        const end = duration();
        if (!row || !Number.isFinite(end) || end <= 0 || row.seconds >= end - 2) { reportResume('Gespeicherte Position ist für dieses Video nicht verfügbar.'); return; }
        try {
            const ranges = typeof media.seekable === 'function' ? media.seekable() : media.seekable;
            let seekable = false;
            for (let i = 0; i < (ranges?.length || 0); i++) if (row.seconds >= ranges.start(i) && row.seconds <= ranges.end(i)) seekable = true;
            if (!seekable) { reportResume('Video erst laden, damit die gespeicherte Position erreichbar ist.'); return; }
            if (typeof media.currentTime === 'function') media.currentTime(row.seconds); else media.currentTime = row.seconds;
            reportResume('Gespeicherte Position geladen. Zum Weiterschauen Play drücken.');
        } catch { reportResume('Diese Quelle unterstützt das Springen zur Position nicht.'); }
    });
    listen(media, 'timeupdate', () => { if (now() - lastSaved >= 5000) { lastSaved = now(); save(); } });
    listen(media, 'pause', save);
    listen(media, 'loadedmetadata', refresh);
    listen(media, 'ended', () => {
        if (consent?.checked && validKey(key)) writePosition(storage, key, 0, now(), true);
        refresh();
        if (!disposed && auto?.checked && next) { auto.checked = false; next.requestSubmit(); }
    });
    listen(media, 'error', () => {
        const error = typeof media.error === 'function' ? media.error() : media.error;
        if (status) status.textContent = playbackError(error?.code);
    });
    refresh();
    return () => { if (disposed) return; save(); disposed = true; for (const remove of listeners) remove(); };
}

if (typeof document !== 'undefined') {
    const active = new Map();
    const start = () => {
        for (const video of document.querySelectorAll('[data-journey-player]')) {
            if (active.has(video)) continue;
            const state = { cancelled: false, cleanup: null, bindCleanup: null };
            active.set(video, state);
            const section = video.closest('[data-journey-section]');
            const status = section?.querySelector('[data-choice-status]');
            let storage; try { storage = localStorage; } catch { /* Playback remains available. */ }
            attachChoice(video, { status, quality: section?.querySelector('[data-quality]'), qualityLabel: section?.querySelector('[data-quality-label]'), isCurrent: () => !state.cancelled })
                .then(cleanup => {
                    if (state.cancelled) { cleanup(); return; }
                    state.cleanup = cleanup;
                    const media = globalThis.videojs?.getPlayer?.(video.id) || video;
                    const next = video.closest('main')?.querySelector('[data-next-video]');
                    state.bindCleanup = bindJourney(media, { key: video.dataset.resumeKey, storage,
                        consent: section?.querySelector('[data-resume-opt-in]'), restore: section?.querySelector('[data-resume-restore]'), erase: section?.querySelector('[data-resume-erase]'),
                        resumeStatus: section?.querySelector('[data-resume-status]'), status, next, auto: next?.querySelector('[data-auto-advance]') });
                }).catch(() => { if (status && !state.cancelled) status.textContent = playbackError(); });
        }
    };
    const stop = () => { for (const state of active.values()) { state.cancelled = true; state.bindCleanup?.(); state.cleanup?.(); } active.clear(); };
    start(); document.addEventListener('turbo:load', start); document.addEventListener('turbo:before-cache', stop);
    document.addEventListener('turbo:before-render', stop); globalThis.addEventListener('pagehide', stop);
}
