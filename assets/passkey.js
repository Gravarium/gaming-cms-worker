export function base64urlToBuffer(value) {
    const padding = '='.repeat((4 - value.length % 4) % 4);
    const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(base64), character => character.charCodeAt(0)).buffer;
}
export function bufferToBase64url(value) {
    let binary = ''; new Uint8Array(value).forEach(byte => { binary += String.fromCharCode(byte); });
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}
export function creationOptions(options, publicKeyCredential = globalThis.PublicKeyCredential) {
    if (publicKeyCredential?.parseCreationOptionsFromJSON) { return publicKeyCredential.parseCreationOptionsFromJSON(options); }
    return {...options, challenge: base64urlToBuffer(options.challenge), user: {...options.user, id: base64urlToBuffer(options.user.id)}, excludeCredentials: (options.excludeCredentials || []).map(item => ({...item, id: base64urlToBuffer(item.id)}))};
}
export function requestOptions(options, publicKeyCredential = globalThis.PublicKeyCredential) {
    if (publicKeyCredential?.parseRequestOptionsFromJSON) { return publicKeyCredential.parseRequestOptionsFromJSON(options); }
    return {...options, challenge: base64urlToBuffer(options.challenge), allowCredentials: (options.allowCredentials || []).map(item => ({...item, id: base64urlToBuffer(item.id)}))};
}
export function credentialJson(credential) {
    if (!credential) { throw new Error('Der Passkey-Vorgang wurde nicht abgeschlossen.'); }
    if (credential.toJSON) { return credential.toJSON(); }
    const response = credential.response;
    const data = {id: credential.id, rawId: bufferToBase64url(credential.rawId), type: credential.type, authenticatorAttachment: credential.authenticatorAttachment, clientExtensionResults: credential.getClientExtensionResults(), response: {clientDataJSON: bufferToBase64url(response.clientDataJSON)}};
    if (response.attestationObject) { data.response.attestationObject = bufferToBase64url(response.attestationObject); data.response.transports = response.getTransports ? response.getTransports() : []; }
    else { data.response.authenticatorData = bufferToBase64url(response.authenticatorData); data.response.signature = bufferToBase64url(response.signature); data.response.userHandle = response.userHandle ? bufferToBase64url(response.userHandle) : null; }
    return data;
}
export async function postJson(url, body, fetcher = globalThis.fetch) {
    const response = await fetcher(url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'}, body: JSON.stringify(body)});
    if (!response.ok) { throw new Error('Die Passkey-Anfrage konnte nicht bestätigt werden.'); }
    return response.status === 204 ? {} : response.json();
}
function showMessage(element, message, isError = false) { if (!element) return; element.hidden = false; element.textContent = message; element.classList.toggle('text-danger', isError); }

if (typeof document !== 'undefined') {
    document.addEventListener('click', async event => {
        const loginButton = event.target.closest('[data-passkey-login]'); const registerButton = event.target.closest('[data-passkey-register]');
        if (!loginButton && !registerButton) return;
        const button = loginButton || registerButton; const message = document.querySelector('[data-passkey-message]');
        if (!globalThis.PublicKeyCredential || !navigator.credentials) { showMessage(message, 'Dieser Browser unterstützt keine Passkeys.', true); return; }
        button.disabled = true; showMessage(message, 'Passkey wird vorbereitet …');
        try {
            if (loginButton) {
                const username = document.querySelector(loginButton.dataset.usernameInput)?.value?.trim();
                if (!username) throw new Error('Bitte zuerst die E-Mail-Adresse eingeben.');
                const options = await postJson(loginButton.dataset.optionsUrl, {username});
                const credential = await navigator.credentials.get({publicKey: requestOptions(options)});
                await postJson(loginButton.dataset.resultUrl, credentialJson(credential)); window.location.assign(loginButton.dataset.successUrl);
            } else {
                const options = await postJson(registerButton.dataset.optionsUrl, {});
                const credential = await navigator.credentials.create({publicKey: creationOptions(options)});
                await postJson(registerButton.dataset.resultUrl, credentialJson(credential)); window.location.reload();
            }
        } catch (error) { showMessage(message, error instanceof Error ? error.message : 'Passkey-Vorgang abgebrochen.', true); button.disabled = false; }
    });
}
