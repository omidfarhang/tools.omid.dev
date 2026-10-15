(function (global) {
  'use strict';

  function b64urlToBuffer(value) {
    var s = String(value).replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s);
    var bytes = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    return bytes.buffer;
  }

  function bufferToB64url(buffer) {
    var bytes = new Uint8Array(buffer);
    var bin = '';
    for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
  }

  function decodeCreationOptions(options) {
    var o = Object.assign({}, options);
    o.challenge = b64urlToBuffer(o.challenge);
    if (o.user && o.user.id) {
      o.user = Object.assign({}, o.user, { id: b64urlToBuffer(o.user.id) });
    }
    if (Array.isArray(o.excludeCredentials)) {
      o.excludeCredentials = o.excludeCredentials.map(function (c) {
        return Object.assign({}, c, { id: b64urlToBuffer(c.id) });
      });
    }
    return o;
  }

  function decodeRequestOptions(options) {
    var o = Object.assign({}, options);
    o.challenge = b64urlToBuffer(o.challenge);
    if (Array.isArray(o.allowCredentials)) {
      o.allowCredentials = o.allowCredentials.map(function (c) {
        return Object.assign({}, c, { id: b64urlToBuffer(c.id) });
      });
    }
    return o;
  }

  function credentialToJson(cred) {
    var response = cred.response;
    var out = {
      id: cred.id,
      rawId: bufferToB64url(cred.rawId),
      type: cred.type,
      response: {}
    };
    if (response.clientDataJSON) {
      out.response.clientDataJSON = bufferToB64url(response.clientDataJSON);
    }
    if (response.attestationObject) {
      out.response.attestationObject = bufferToB64url(response.attestationObject);
    }
    if (response.authenticatorData) {
      out.response.authenticatorData = bufferToB64url(response.authenticatorData);
    }
    if (response.signature) {
      out.response.signature = bufferToB64url(response.signature);
    }
    if (response.userHandle) {
      out.response.userHandle = bufferToB64url(response.userHandle);
    }
    if (typeof response.getTransports === 'function') {
      try {
        out.response.transports = response.getTransports();
      } catch (e) {}
    }
    if (cred.authenticatorAttachment) {
      out.authenticatorAttachment = cred.authenticatorAttachment;
    }
    return out;
  }

  async function postJson(url, body) {
    var res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body)
    });
    var data = await res.json().catch(function () {
      return { ok: false, error: 'Unexpected server response.' };
    });
    if (!res.ok && data && !data.error) {
      data.error = 'Request failed (' + res.status + ').';
    }
    return data;
  }

  async function registerPasskey(csrf, label) {
    if (!global.PublicKeyCredential) {
      throw new Error('Passkeys are not supported in this browser.');
    }
    var optRes = await postJson('/user/webauthn_register_options.php', { _csrf: csrf });
    if (optRes.ok === false) {
      throw new Error(optRes.error || 'Could not start passkey registration.');
    }
    var publicKey = decodeCreationOptions(optRes);
    var cred = await navigator.credentials.create({ publicKey: publicKey });
    if (!cred) {
      throw new Error('Passkey registration was cancelled.');
    }
    var result = await postJson('/user/webauthn_register.php', {
      _csrf: csrf,
      label: label || '',
      credential: credentialToJson(cred)
    });
    if (result.ok === false) {
      throw new Error(result.error || 'Could not save passkey.');
    }
    return result;
  }

  async function assertPasskey(csrf, rememberDevice) {
    if (!global.PublicKeyCredential) {
      throw new Error('Passkeys are not supported in this browser.');
    }
    var optRes = await postJson('/webauthn_assert_options.php', { _csrf: csrf });
    if (optRes.ok === false) {
      throw new Error(optRes.error || 'Could not start passkey login.');
    }
    var publicKey = decodeRequestOptions(optRes);
    var cred = await navigator.credentials.get({ publicKey: publicKey });
    if (!cred) {
      throw new Error('Passkey authentication was cancelled.');
    }
    var result = await postJson('/webauthn_assert.php', {
      _csrf: csrf,
      remember_device: !!rememberDevice,
      credential: credentialToJson(cred)
    });
    if (result.ok === false) {
      throw new Error(result.error || 'Passkey verification failed.');
    }
    return result;
  }

  global.ToolsWebAuthn = {
    registerPasskey: registerPasskey,
    assertPasskey: assertPasskey
  };
})(window);
