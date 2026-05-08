window.timeclockWebAuthn = window.timeclockWebAuthn || {
    csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    },
    /**
     * @param {string} input
     * @returns {Uint8Array}
     */
    decode(input) {
        input = input.replace(/-/g, '+').replace(/_/g, '/');
        const pad = input.length % 4;
        if (pad) input += '='.repeat(4 - pad);
        return Uint8Array.from(atob(input), char => char.charCodeAt(0));
    },
    /**
     * @param {ArrayBuffer} buffer
     * @returns {string}
     */
    encode(buffer) {
        return btoa(String.fromCharCode(...new Uint8Array(buffer)));
    },
    /**
     * @param {Record<string, any>} publicKey
     * @returns {Record<string, any>}
     */
    parseOptions(publicKey) {
        publicKey.challenge = this.decode(publicKey.challenge);

        if (publicKey.user?.id) {
            publicKey.user.id = this.decode(publicKey.user.id);
        }

        for (const key of ['excludeCredentials', 'allowCredentials']) {
            if (!publicKey[key]) continue;
            /** @param {{ id: string }} credential */
            publicKey[key] = publicKey[key].map(credential => ({
                ...credential,
                id: this.decode(credential.id),
            }));
        }

        return publicKey;
    },
    /**
     * @param {PublicKeyCredential} credential
     * @returns {Record<string, any>}
     */
    parseCredential(credential) {
        /** @type {Record<string, string>} */
        const response = {};
        const authenticatorResponse = /** @type {Record<string, ArrayBuffer | undefined>} */ (credential.response);

        for (const key of ['clientDataJSON', 'attestationObject', 'authenticatorData', 'signature', 'userHandle']) {
            if (authenticatorResponse[key]) {
                response[key] = this.encode(authenticatorResponse[key]);
            }
        }

        return {
            id: credential.id,
            rawId: this.encode(credential.rawId),
            type: credential.type,
            authenticatorAttachment: credential.authenticatorAttachment,
            clientExtensionResults: credential.getClientExtensionResults(),
            response,
        };
    },
    /**
     * @param {string} url
     * @param {Record<string, any>} data
     * @returns {Promise<Record<string, any>>}
     */
    async postJson(url, data = {}) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(data),
        });

        if (!response.ok) {
            const payload = await response.json().catch(() => ({}));
            throw new Error(payload.message || 'The WebAuthn request failed.');
        }

        return response.json().catch(() => ({}));
    },
};

/**
 * @param {{ optionsUrl: string, registerUrl: string }} config
 */
window.fingerprintEnrollment = ({optionsUrl, registerUrl}) => ({
    loading: false,
    message: '',
    success: false,
    supported: typeof PublicKeyCredential !== 'undefined',
    async enroll() {
        this.loading = true;
        this.message = '';
        this.success = false;

        try {
            const options = await window.timeclockWebAuthn.postJson(optionsUrl);
            const credential = await navigator.credentials.create({
                publicKey: window.timeclockWebAuthn.parseOptions(options),
            });

            await window.timeclockWebAuthn.postJson(registerUrl, {
                ...window.timeclockWebAuthn.parseCredential(credential),
                alias: 'Fingerprint',
            });

            this.success = true;
            this.message = 'Fingerprint enrolled successfully.';
        } catch (error) {
            this.message = error instanceof Error ? error.message : 'Fingerprint enrollment failed.';
        } finally {
            this.loading = false;
        }
    },
});
