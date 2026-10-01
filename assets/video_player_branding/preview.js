export function requestPreviewFullscreen(frame) {
    if (!frame || typeof frame.requestFullscreen !== 'function') return Promise.resolve(false);
    return Promise.resolve(frame.requestFullscreen()).then(() => true, () => false);
}

if (typeof document !== 'undefined') {
    const bind = () => {
        for (const button of document.querySelectorAll('[data-branding-fullscreen]')) {
            if (button.dataset.brandingBound === '1') continue;
            button.dataset.brandingBound = '1';
            button.addEventListener('click', () => requestPreviewFullscreen(button.closest('section')?.querySelector('[data-branding-frame]')));
        }
    };
    bind(); document.addEventListener('turbo:load', bind);
}
