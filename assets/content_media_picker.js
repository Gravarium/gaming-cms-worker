const CONTROL_CHARACTERS = /[\u0000-\u001f\u007f]/u;
const INTERNAL_MEDIA_PREFIX = '/uploads/media/';

export function normalizeMediaPickerItem(value) {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return null;
    }

    if (!Number.isSafeInteger(value.id) || value.id < 1
        || typeof value.title !== 'string'
        || typeof value.originalName !== 'string'
        || typeof value.altText !== 'string'
        || typeof value.url !== 'string'
        || CONTROL_CHARACTERS.test(value.title)
        || CONTROL_CHARACTERS.test(value.originalName)
        || CONTROL_CHARACTERS.test(value.altText)
        || CONTROL_CHARACTERS.test(value.url)
    ) {
        return null;
    }

    if (value.url.startsWith(INTERNAL_MEDIA_PREFIX)) {
        if (value.url.includes('..') || value.url.includes('\\') || value.url.startsWith('//')) {
            return null;
        }
    } else {
        let parsed;
        try {
            parsed = new URL(value.url);
        } catch {
            return null;
        }
        if (parsed.protocol !== 'https:' || parsed.username !== '' || parsed.password !== '') {
            return null;
        }
    }

    return {
        id: value.id,
        title: value.title.slice(0, 180),
        originalName: value.originalName.slice(0, 255),
        altText: value.altText.slice(0, 255),
        url: value.url,
    };
}

export function applyMediaSelection(block, item) {
    if (typeof block !== 'object' || block === null || Array.isArray(block) || block.type !== 'media') {
        throw new TypeError('Eine Bildauswahl benötigt einen Medienblock.');
    }

    const normalized = normalizeMediaPickerItem(item);
    if (normalized === null) {
        throw new TypeError('Das ausgewählte Medium ist ungültig.');
    }

    block.assetId = normalized.id;
    if (typeof block.alt !== 'string' || block.alt.trim() === '') {
        block.alt = normalized.altText;
    }

    return block;
}
