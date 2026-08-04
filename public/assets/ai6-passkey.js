(() => {
  "use strict";

  const decodeBase64Url = (value) => {
    const normalized = value.replace(/-/g, "+").replace(/_/g, "/");
    const padded = normalized + "=".repeat((4 - (normalized.length % 4)) % 4);
    const binary = window.atob(padded);
    return Uint8Array.from(binary, (character) => character.charCodeAt(0));
  };

  const encodeBase64Url = (value) => {
    const bytes = new Uint8Array(value);
    let binary = "";
    bytes.forEach((byte) => {
      binary += String.fromCharCode(byte);
    });
    return window.btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/g, "");
  };

  const requestJson = async (url, csrfToken, body = {}) => {
    const response = await window.fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-CSRF-TOKEN": csrfToken,
      },
      body: JSON.stringify(body),
    });

    if (!response.ok) {
      throw new Error("request-failed");
    }

    return response.json();
  };

  document.querySelectorAll("[data-passkey-panel]").forEach((panel) => {
    const button = panel.querySelector("[data-passkey-trigger]");
    const status = panel.querySelector("[data-passkey-status]");
    const csrfToken = panel.querySelector('input[name="_token"]')?.value;

    if (!(button instanceof HTMLButtonElement) || !(status instanceof HTMLElement) || !csrfToken) {
      return;
    }

    button.addEventListener("click", async () => {
      button.disabled = true;
      status.textContent = "Passkey-Prüfung läuft …";

      try {
        const optionsDocument = await requestJson(panel.dataset.optionsUrl, csrfToken);
        const publicKey = optionsDocument.publicKey;
        publicKey.challenge = decodeBase64Url(publicKey.challenge);

        let credential;
        let payload;

        if (panel.dataset.mode === "create") {
          publicKey.user.id = decodeBase64Url(publicKey.user.id);
          publicKey.excludeCredentials = (publicKey.excludeCredentials || []).map((item) => ({
            ...item,
            id: decodeBase64Url(item.id),
          }));
          credential = await navigator.credentials.create({ publicKey });
          payload = {
            credential_id: encodeBase64Url(credential.rawId),
            client_data_json: encodeBase64Url(credential.response.clientDataJSON),
            attestation_object: encodeBase64Url(credential.response.attestationObject),
            label: panel.querySelector('input[name="label"]')?.value || null,
          };
        } else {
          publicKey.allowCredentials = (publicKey.allowCredentials || []).map((item) => ({
            ...item,
            id: decodeBase64Url(item.id),
          }));
          credential = await navigator.credentials.get({ publicKey });
          payload = {
            credential_id: encodeBase64Url(credential.rawId),
            client_data_json: encodeBase64Url(credential.response.clientDataJSON),
            authenticator_data: encodeBase64Url(credential.response.authenticatorData),
            signature: encodeBase64Url(credential.response.signature),
            user_handle: credential.response.userHandle
              ? encodeBase64Url(credential.response.userHandle)
              : null,
          };
        }

        const result = await requestJson(panel.dataset.submitUrl, csrfToken, payload);
        if (result.redirect) {
          window.location.assign(result.redirect);
          return;
        }

        status.textContent = "Passkey bestätigt.";
      } catch (_error) {
        status.textContent = "Die Passkey-Prüfung wurde abgelehnt.";
        button.disabled = false;
      }
    });
  });
})();
