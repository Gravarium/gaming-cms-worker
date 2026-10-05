function safeDocumentationUrl(value) {
    try {
        const url = new URL(value);
        if (url.protocol !== 'https:' || url.username !== '' || url.password !== '') return null;
        return url.href;
    } catch {
        return null;
    }
}

export function bindProviderDocumentation(select, link) {
    const update = () => {
        const options = Array.from(select.options ?? []);
        const option = options.find(candidate => candidate.value === select.value) ?? null;
        const documentation = safeDocumentationUrl(option?.dataset?.documentation ?? '');
        if (!option || documentation === null) {
            link.removeAttribute('href');
            link.hidden = true;
            return;
        }
        const label = (option.dataset?.providerLabel ?? option.textContent ?? 'Anbieter').trim();
        link.href = documentation;
        link.textContent = `${label} · Dokumentation`;
        link.hidden = false;
    };
    select.addEventListener('change', update);
    update();
    return () => select.removeEventListener('change', update);
}

if (typeof document !== 'undefined') {
    const select = document.querySelector('[data-provider-documentation-select]');
    const link = document.querySelector('[data-provider-documentation-link]');
    if (select && link) bindProviderDocumentation(select, link);
}
