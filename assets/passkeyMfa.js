(function () {
  'use strict';

  function arrayBufferToBase64(buffer) {
    var binary = '';
    var bytes = new Uint8Array(buffer);
    for (var i = 0; i < bytes.byteLength; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return window.btoa(binary);
  }

  function binaryStringToArrayBuffer(value) {
    var binary = window.atob(value);
    var bytes = new Uint8Array(binary.length);
    for (var i = 0; i < binary.length; i++) {
      bytes[i] = binary.charCodeAt(i);
    }
    return bytes.buffer;
  }

  function recursiveBase64StrToArrayBuffer(obj) {
    var prefix = '=?BINARY?B?';
    var suffix = '?=';
    if (!obj || typeof obj !== 'object') {
      return;
    }

    Object.keys(obj).forEach(function (key) {
      var value = obj[key];
      if (typeof value === 'string' && value.substring(0, prefix.length) === prefix && value.substring(value.length - suffix.length) === suffix) {
        obj[key] = binaryStringToArrayBuffer(value.substring(prefix.length, value.length - suffix.length));
      } else if (value && typeof value === 'object') {
        recursiveBase64StrToArrayBuffer(value);
      }
    });
  }

  async function fetchJson(url, options) {
    var response = await window.fetch(url, Object.assign({ cache: 'no-cache' }, options || {}));
    var data = await response.json();
    if (!response.ok || data.success === false) {
      throw new Error(data.message || data.msg || 'Passkey request failed.');
    }
    return data;
  }

  function checkSupport() {
    if (!window.PublicKeyCredential || !navigator.credentials || !navigator.credentials.create || !navigator.credentials.get) {
      throw new Error('This browser does not support passkeys.');
    }
  }

  function showMessage(message, type) {
    var alert = document.createElement('div');
    alert.className = 'alert alert-' + (type || 'info') + ' small';
    alert.textContent = message;
    var main = document.querySelector('main, .container-fluid, body');
    main.insertBefore(alert, main.firstChild);
  }

  async function setup(config) {
    var button = document.querySelector(config.registerButton);
    if (button) {
      button.addEventListener('click', async function () {
        try {
          checkSupport();
          button.disabled = true;
          var options = await fetchJson(config.baseUrlPrefix + '/mfa/passkey/register/options');
          recursiveBase64StrToArrayBuffer(options);

          var credential = await navigator.credentials.create(options);
          var response = credential.response;
          var payload = {
            name: window.navigator.platform ? 'Passkey - ' + window.navigator.platform : 'Passkey',
            transports: response.getTransports ? response.getTransports() : null,
            clientDataJSON: response.clientDataJSON ? arrayBufferToBase64(response.clientDataJSON) : null,
            attestationObject: response.attestationObject ? arrayBufferToBase64(response.attestationObject) : null
          };

          await fetchJson(config.baseUrlPrefix + '/mfa/passkey/register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrfToken || '' },
            body: JSON.stringify(payload)
          });
          window.location.reload();
        } catch (error) {
          showMessage(error.message || 'Passkey registration failed.', 'danger');
          button.disabled = false;
        }
      });
    }

    document.querySelectorAll(config.deleteSelector || '[data-passkey-delete]').forEach(function (deleteButton) {
      deleteButton.addEventListener('click', async function () {
        try {
          deleteButton.disabled = true;
          await fetchJson(config.baseUrlPrefix + '/mfa/passkey/delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrfToken || '' },
            body: JSON.stringify({ id: deleteButton.getAttribute('data-passkey-delete') })
          });
          window.location.reload();
        } catch (error) {
          showMessage(error.message || 'Could not remove passkey.', 'danger');
          deleteButton.disabled = false;
        }
      });
    });
  }

  async function verify(config) {
    var button = document.querySelector(config.button);
    if (!button) {
      return;
    }

    button.addEventListener('click', async function () {
      try {
        checkSupport();
        button.disabled = true;
        var options = await fetchJson(config.baseUrlPrefix + '/mfa/passkey/verify/options');
        recursiveBase64StrToArrayBuffer(options);

        var credential = await navigator.credentials.get(options);
        var response = credential.response;
        var result = await fetchJson(config.baseUrlPrefix + '/mfa/passkey/verify', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            id: credential.rawId ? arrayBufferToBase64(credential.rawId) : null,
            clientDataJSON: response.clientDataJSON ? arrayBufferToBase64(response.clientDataJSON) : null,
            authenticatorData: response.authenticatorData ? arrayBufferToBase64(response.authenticatorData) : null,
            signature: response.signature ? arrayBufferToBase64(response.signature) : null,
            userHandle: response.userHandle ? arrayBufferToBase64(response.userHandle) : null
          })
        });

        window.location.href = result.redirect || (config.baseUrlPrefix + '/');
      } catch (error) {
        showMessage(error.message || 'Passkey verification failed.', 'danger');
        button.disabled = false;
      }
    });
  }

  window.poweradminPasskeyMfa = {
    setup: setup,
    verify: verify
  };
})();
