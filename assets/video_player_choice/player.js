import { attachPlayer } from '../video_workspace/player.js';

const preferenceKey = 'video-player-choice-v1';
let libraryPromise;
export function preferEngine(select, storage) {
    try {
        const preferred = storage?.getItem(preferenceKey);
        if (Array.from(select.options).some(option => option.value === preferred)) select.value = preferred;
    } catch { /* Storage may be disabled; the form remains usable. */ }
}
export function rememberEngine(value, storage) {
    if (!['native', 'videojs', 'embed', 'youtube', 'link'].includes(value)) return;
    try { storage?.setItem(preferenceKey, value); } catch { /* No storage required for playback. */ }
}

function loadVideoJs(video) {
    if (typeof globalThis.videojs === 'function') return Promise.resolve(globalThis.videojs);
    if (!libraryPromise) libraryPromise = new Promise((resolve, reject) => {
        const url = new URL(video.dataset.library, document.baseURI);
        if (url.origin !== location.origin || !url.pathname.endsWith('/video-8.24.1.min.js')) { reject(new Error('Invalid library')); return; }
        const script = document.createElement('script');
        script.src = url.href;
        script.onload = () => typeof globalThis.videojs === 'function' ? resolve(globalThis.videojs) : reject(new Error('Player unavailable'));
        script.onerror = () => reject(new Error('Player unavailable'));
        document.head.append(script);
    }).catch(error => { libraryPromise = null; throw error; });
    return libraryPromise;
}

export async function attachChoice(video, { loadLibrary = () => loadVideoJs(video), nativePlayer = attachPlayer,
    status = null, isCurrent = () => true, quality = null, qualityLabel = null } = {}) {
    const report = message => { if (status) status.textContent = message; };
    let disposed = false, player = null, cleanupNative = null;
    const listeners = [];
    const listen = (event, handler) => { video.addEventListener(event, handler); listeners.push([event, handler]); };
    const current = () => !disposed && isCurrent();
    const cleanup = () => {
        if (disposed) return;
        disposed = true;
        for (const [event, handler] of listeners) video.removeEventListener(event, handler);
        if (player) { player.dispose(); player = null; }
        cleanupNative?.();
    };
    if (!['video', 'hls'].includes(video.dataset.mode) || !['native', 'videojs'].includes(video.dataset.engine) || !video.dataset.source) return cleanup;
    const start = Math.max(0, Math.min(86400, Number(video.dataset.start) || 0));
    const speed = [0.5, 0.75, 1, 1.25, 1.5, 2].includes(Number(video.dataset.speed)) ? Number(video.dataset.speed) : 1;
    const autoplay = video.dataset.autoplay === '1';
    video.muted = video.dataset.muted === '1'; video.loop = video.dataset.loop === '1'; video.autoplay = autoplay;
    const configureNative = () => {
        if (!current()) return;
        video.playbackRate = speed;
        if (start > 0) { try { video.currentTime = start; } catch { /* A live source may not seek yet. */ } }
        if (autoplay) Promise.resolve(video.play()).catch(() => report('Bitte Play drücken; der Browser hat den automatischen Start verhindert.'));
    };
    if (video.dataset.engine === 'videojs') {
        try {
            const videojs = await loadLibrary();
            if (!current()) return cleanup;
            video.classList.add('video-js');
            player = videojs(video, { controls: true, autoplay, preload: 'none', fluid: true,
                muted: video.muted, loop: video.loop, playbackRates: [0.5, 0.75, 1, 1.25, 1.5, 2],
                html5: { vhs: { withCredentials: false } },
                sources: [{ src: video.dataset.source, type: video.dataset.mode === 'hls' ? 'application/x-mpegURL' : new URL(video.dataset.source, 'https://localhost').pathname.toLowerCase().endsWith('.webm') ? 'video/webm' : 'video/mp4' }] });
            player.on('loadedmetadata', () => {
                if (!current() || !player) return;
                player.playbackRate(speed);
                if (start > 0) { try { player.currentTime(start); } catch { /* Seek is optional for live. */ } }
                if (autoplay) Promise.resolve(player.play()).catch(() => report('Bitte Play drücken; der Browser hat den automatischen Start verhindert.'));
            });
            player.on('error', () => report('Die Quelle kann nicht abgespielt werden. Bitte eine andere Quelle oder den Browser-Player wählen.'));
            return cleanup;
        } catch {
            player?.dispose(); player = null;
            if (!current()) return cleanup;
            report('Video.js konnte nicht geladen werden. Der Browser-Player wird verwendet.');
            video.classList.remove('video-js');
        }
    }
    listen('loadedmetadata', configureNative);
    cleanupNative = await nativePlayer(video, { status, quality, qualityLabel, isCurrent: current });
    if (!current()) cleanup();
    return cleanup;
}

if (typeof document !== 'undefined') {
    const active = new Map();
    const start = () => {
        let storage;
        try { storage = localStorage; } catch { /* Browser privacy setting. */ }
        for (const form of document.querySelectorAll('[data-choice-form]')) {
            if (form.dataset.choiceBound === '1') continue;
            form.dataset.choiceBound = '1';
            const select = form.querySelector('[data-engine]');
            if (!select) continue;
            // Server-rendered playback selection wins over a stored preference.
            if (!document.querySelector('[data-choice-player], .workspace-player iframe')) preferEngine(select, storage);
            form.addEventListener('submit', () => rememberEngine(select.value, storage));
        }
        for (const video of document.querySelectorAll('[data-choice-player]')) {
            if (active.has(video)) continue;
            const state = { cancelled: false, cleanup: null };
            active.set(video, state);
            const section = video.closest('section');
            attachChoice(video, { status: section?.querySelector('[data-choice-status]'),
                quality: section?.querySelector('[data-quality]'), qualityLabel: section?.querySelector('[data-quality-label]'),
                isCurrent: () => !state.cancelled }).then(cleanup => { if (state.cancelled) cleanup(); else state.cleanup = cleanup; });
        }
    };
    const stop = () => {
        for (const state of active.values()) { state.cancelled = true; state.cleanup?.(); }
        active.clear();
    };
    start();
    document.addEventListener('turbo:load', start);
    document.addEventListener('turbo:before-cache', stop);
    document.addEventListener('turbo:before-render', stop);
}
