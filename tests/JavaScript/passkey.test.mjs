import test from 'node:test';
import assert from 'node:assert/strict';
import {base64urlToBuffer, bufferToBase64url, creationOptions, requestOptions, credentialJson, postJson} from '../../assets/passkey.js';

const bytes = (...values) => Uint8Array.from(values).buffer;

test('base64url codec round-trips binary credential data', () => {
    const source = bytes(0, 1, 2, 127, 128, 254, 255);
    assert.deepEqual(new Uint8Array(base64urlToBuffer(bufferToBase64url(source))), new Uint8Array(source));
});

test('creation fallback decodes challenge, user and excluded credential IDs', () => {
    const result = creationOptions({challenge:'AQI', user:{id:'AwQ', name:'a'}, excludeCredentials:[{type:'public-key', id:'BQY'}]}, {});
    assert.deepEqual([...new Uint8Array(result.challenge)], [1,2]);
    assert.deepEqual([...new Uint8Array(result.user.id)], [3,4]);
    assert.deepEqual([...new Uint8Array(result.excludeCredentials[0].id)], [5,6]);
});

test('request fallback decodes challenge and allowed credential IDs', () => {
    const result = requestOptions({challenge:'AQI', allowCredentials:[{type:'public-key', id:'AwQ'}]}, {});
    assert.deepEqual([...new Uint8Array(result.challenge)], [1,2]);
    assert.deepEqual([...new Uint8Array(result.allowCredentials[0].id)], [3,4]);
});

test('native WebAuthn JSON parsers are preferred when available', () => {
    const creation = {parseCreationOptionsFromJSON: value => ({native:'creation', value})};
    const request = {parseRequestOptionsFromJSON: value => ({native:'request', value})};
    assert.equal(creationOptions({challenge:'x'}, creation).native, 'creation');
    assert.equal(requestOptions({challenge:'x'}, request).native, 'request');
});

test('assertion credential fallback serializes binary fields without leaking raw buffers', () => {
    const result = credentialJson({
        id:'credential', rawId:bytes(1), type:'public-key', authenticatorAttachment:'platform',
        getClientExtensionResults:()=>({}),
        response:{clientDataJSON:bytes(2), authenticatorData:bytes(3), signature:bytes(4), userHandle:bytes(5)}
    });
    assert.equal(result.rawId, 'AQ');
    assert.equal(result.response.clientDataJSON, 'Ag');
    assert.equal(result.response.authenticatorData, 'Aw');
    assert.equal(result.response.signature, 'BA');
    assert.equal(result.response.userHandle, 'BQ');
});

test('cancelled credential fails closed', () => {
    assert.throws(() => credentialJson(null), /nicht abgeschlossen/);
});

test('postJson uses same-origin JSON and rejects non-success responses', async () => {
    let request;
    const fetcher = async (url, options) => { request={url,options}; return {ok:true,status:204}; };
    assert.deepEqual(await postJson('/passkey', {a:1}, fetcher), {});
    assert.equal(request.url, '/passkey');
    assert.equal(request.options.credentials, 'same-origin');
    assert.equal(request.options.headers['Content-Type'], 'application/json');
    await assert.rejects(() => postJson('/passkey', {}, async()=>({ok:false,status:403})), /nicht bestätigt/);
});
