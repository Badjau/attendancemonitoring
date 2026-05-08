<div
    class="space-y-4"
    x-data="fingerprintEnrollment({
        optionsUrl: @js(route('admin.employees.fingerprint.options', $employee)),
        registerUrl: @js(route('admin.employees.fingerprint.register', $employee)),
    })"
>
    <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        <div class="font-semibold text-gray-950 dark:text-white">{{ $employee->name }}</div>
        <div class="mt-1 text-gray-500 dark:text-gray-400">{{ $employee->employee_id }} &middot; {{ $employee->position }}</div>
    </div>

    <template x-if="! supported">
        <div class="rounded-lg border border-danger-200 bg-danger-50 p-4 text-sm text-danger-700 dark:border-danger-800 dark:bg-danger-950 dark:text-danger-300">
            This browser does not support WebAuthn or biometric credentials.
        </div>
    </template>

    <template x-if="message">
        <div
            class="rounded-lg border p-4 text-sm"
            :class="success ? 'border-success-200 bg-success-50 text-success-700 dark:border-success-800 dark:bg-success-950 dark:text-success-300' : 'border-warning-200 bg-warning-50 text-warning-700 dark:border-warning-800 dark:bg-warning-950 dark:text-warning-300'"
            x-text="message"
        ></div>
    </template>

    <button
        type="button"
        class="fi-btn fi-btn-size-md fi-color-primary"
        :disabled="loading || ! supported"
        @click="enroll"
    >
        <span x-show="! loading">Start fingerprint enrollment</span>
        <span x-show="loading">Waiting for browser verification...</span>
    </button>
</div>

@once
    <script>
        window.timeclockWebAuthn = window.timeclockWebAuthn || {
            csrfToken() {
                return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            },
            decode(input) {
                input = input.replace(/-/g, '+').replace(/_/g, '/');
                const pad = input.length % 4;
                if (pad) input += '='.repeat(4 - pad);
                return Uint8Array.from(atob(input), char => char.charCodeAt(0));
            },
            encode(buffer) {
                return btoa(String.fromCharCode(...new Uint8Array(buffer)));
            },
            parseOptions(publicKey) {
                publicKey.challenge = this.decode(publicKey.challenge);

                if (publicKey.user?.id) {
                    publicKey.user.id = this.decode(publicKey.user.id);
                }

                for (const key of ['excludeCredentials', 'allowCredentials']) {
                    if (! publicKey[key]) continue;
                    publicKey[key] = publicKey[key].map(credential => ({
                        ...credential,
                        id: this.decode(credential.id),
                    }));
                }

                return publicKey;
            },
            parseCredential(credential) {
                const response = {};

                for (const key of ['clientDataJSON', 'attestationObject', 'authenticatorData', 'signature', 'userHandle']) {
                    if (credential.response[key]) {
                        response[key] = this.encode(credential.response[key]);
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

                if (! response.ok) {
                    const payload = await response.json().catch(() => ({}));
                    throw new Error(payload.message || 'The WebAuthn request failed.');
                }

                return response.json().catch(() => ({}));
            },
        };

        document.addEventListener('alpine:init', () => {
            Alpine.data('fingerprintEnrollment', ({ optionsUrl, registerUrl }) => ({
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
            }));
        });
    </script>
@endonce
