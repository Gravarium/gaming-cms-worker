export async function attachPlayer(video, { loadHls = () => import('hls.js'), status = null, quality = null, qualityLabel = null } = {}) {
    const url = video.dataset.source;
    const mode = video.dataset.mode;
    if (!url || !['video', 'hls'].includes(mode)) return () => {};
    const report = message => { if (status) status.textContent = message; };
    let hls = null;
    const cleanup = () => { hls?.destroy(); hls = null; video.removeAttribute('src'); video.load(); };
    video.addEventListener('error', () => report('Die Quelle kann nicht abgespielt werden. Bitte eine andere Quelle auswählen.'));
    if (mode === 'video' || video.canPlayType('application/vnd.apple.mpegurl')) {
        video.src = url;
        return cleanup;
    }
    try {
        const { default: Hls } = await loadHls();
        if (!Hls.isSupported()) { report('Dieser Browser unterstützt das Streamformat nicht. Bitte eine andere Quelle wählen.'); return cleanup; }
        hls = new Hls({ enableWorker: false, lowLatencyMode: true, maxBufferLength: 30, xhrSetup: xhr => { xhr.withCredentials = false; } });
        hls.on(Hls.Events.ERROR, (_event, data) => {
            if (data.fatal) { report('Stream nicht erreichbar. Prüfe die Quellfreigabe oder wähle eine andere Quelle.'); hls?.destroy(); hls = null; }
        });
        hls.on(Hls.Events.MANIFEST_PARSED, () => {
            if (!quality || !hls) return;
            for (const [index, level] of hls.levels.entries()) {
                const option = quality.ownerDocument.createElement('option');
                option.value = String(index); option.textContent = level.height ? `${level.height}p` : `${Math.round(level.bitrate / 1000)} kbit/s`;
                quality.append(option);
            }
            if (qualityLabel) qualityLabel.hidden = false;
            quality.addEventListener('change', () => { if (hls) hls.currentLevel = Number(quality.value); });
        });
        hls.loadSource(url); hls.attachMedia(video);
    } catch { report('Der Stream-Player konnte nicht geladen werden. Bitte eine andere Quelle wählen.'); }
    return cleanup;
}

if (typeof document !== 'undefined') {
    const cleanups = [];
    for (const video of document.querySelectorAll('[data-workspace-player]')) {
        const section = video.closest('section');
        attachPlayer(video, { status: section?.querySelector('[data-player-status]'), quality: section?.querySelector('[data-quality]'), qualityLabel: section?.querySelector('[data-quality-label]') }).then(cleanup => cleanups.push(cleanup));
    }
    document.addEventListener('turbo:before-cache', () => { cleanups.splice(0).forEach(cleanup => cleanup()); }, { once: true });
}
