window.timeclockWebAuthn = window.timeclockWebAuthn || {
    csrfToken() {
        return (
            document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content') || ''
        )
    },
    /**
     * @param {string} input
     * @returns {Uint8Array}
     */
    decode(input) {
        input = input.replace(/-/g, '+').replace(/_/g, '/')
        const pad = input.length % 4
        if (pad) input += '='.repeat(4 - pad)
        return Uint8Array.from(atob(input), (char) => char.charCodeAt(0))
    },
    /**
     * @param {ArrayBuffer} buffer
     * @returns {string}
     */
    encode(buffer) {
        return btoa(String.fromCharCode(...new Uint8Array(buffer)))
    },
    /**
     * @param {Record<string, any>} publicKey
     * @returns {Record<string, any>}
     */
    parseOptions(publicKey) {
        publicKey.challenge = this.decode(publicKey.challenge)

        if (publicKey.user?.id) {
            publicKey.user.id = this.decode(publicKey.user.id)
        }

        for (const key of ['excludeCredentials', 'allowCredentials']) {
            if (!publicKey[key]) continue
            /** @param {{ id: string }} credential */
            publicKey[key] = publicKey[key].map((credential) => ({
                ...credential,
                id: this.decode(credential.id),
            }))
        }

        return publicKey
    },
    /**
     * @param {PublicKeyCredential} credential
     * @returns {Record<string, any>}
     */
    parseCredential(credential) {
        /** @type {Record<string, string>} */
        const response = {}
        const authenticatorResponse =
            /** @type {Record<string, ArrayBuffer | undefined>} */ (
                credential.response
            )

        for (const key of [
            'clientDataJSON',
            'attestationObject',
            'authenticatorData',
            'signature',
            'userHandle',
        ]) {
            if (authenticatorResponse[key]) {
                response[key] = this.encode(authenticatorResponse[key])
            }
        }

        return {
            id: credential.id,
            rawId: this.encode(credential.rawId),
            type: credential.type,
            authenticatorAttachment: credential.authenticatorAttachment,
            clientExtensionResults: credential.getClientExtensionResults(),
            response,
        }
    },
    /**
     * @param {string} url
     * @param {Record<string, any>} data
     * @param {string} method
     * @returns {Promise<Record<string, any>>}
     */
    async postJson(url, data = {}, method = 'POST') {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(data),
        })

        if (!response.ok) {
            const payload = await response.json().catch(() => ({}))
            throw new Error(payload.message || 'The WebAuthn request failed.')
        }

        return response.json().catch(() => ({}))
    },
}

/**
 * @param {{ optionsUrl: string, registerUrl: string, deleteUrl: string, zktecoBridgeUrl?: string, employee?: Record<string, any>, registeredFingers?: string[] }} config
 */
window.fingerprintEnrollment = ({
    optionsUrl,
    registerUrl,
    deleteUrl,
    zktecoBridgeUrl = 'http://127.0.0.1:8765',
    employee = {},
    registeredFingers = [],
}) => ({
    loading: false,
    zktecoLoading: false,
    message: '',
    success: false,
    supported: typeof PublicKeyCredential !== 'undefined',
    selectedFinger: '',
    requiredTaps: 3,
    completedTaps: 0,
    activeTap: 0,
    fingers: [
        { value: 'left-thumb', label: 'Left thumb' },
        { value: 'left-index', label: 'Left index' },
        { value: 'left-middle', label: 'Left middle' },
        { value: 'left-ring', label: 'Left ring' },
        { value: 'left-little', label: 'Left little' },
        { value: 'right-thumb', label: 'Right thumb' },
        { value: 'right-index', label: 'Right index' },
        { value: 'right-middle', label: 'Right middle' },
        { value: 'right-ring', label: 'Right ring' },
        { value: 'right-little', label: 'Right little' },
    ],
    registeredFingers,
    zktecoBridgeUrl,
    employee,

    get selectedFingerLabel() {
        return (
            this.fingers.find((finger) => finger.value === this.selectedFinger)
                ?.label || ''
        )
    },

    get selectedFingerRegistered() {
        return this.registeredFingers.includes(this.selectedFingerLabel)
    },

    get canEnroll() {
        return (
            this.supported &&
            !this.loading &&
            Boolean(this.selectedFinger) &&
            !this.selectedFingerRegistered &&
            this.completedTaps < this.requiredTaps
        )
    },

    isRegistered(finger) {
        return this.registeredFingers.includes(finger.label)
    },

    selectFinger(finger) {
        if (this.loading) return

        this.selectedFinger = finger.value
        this.completedTaps = 0
        this.activeTap = 0
        this.message = ''
        this.success = false

        if (this.selectedFingerRegistered) {
            this.message = `${this.selectedFingerLabel} is already registered. Remove it before registering again.`
        }
    },

    async enroll() {
        if (!this.selectedFinger) {
            this.message = 'Select which finger to enroll first.'
            this.success = false
            return
        }

        if (this.selectedFingerRegistered) {
            this.message = `${this.selectedFingerLabel} is already registered. Remove it before registering again.`
            this.success = false
            return
        }

        this.loading = true
        this.message = ''
        this.success = false

        try {
            const tap = this.completedTaps + 1
            this.activeTap = tap
            this.message = `Tap ${tap} of ${this.requiredTaps}: waiting for ${this.selectedFingerLabel}.`

            const options = await window.timeclockWebAuthn.postJson(optionsUrl)
            const credential = await navigator.credentials.create({
                publicKey: window.timeclockWebAuthn.parseOptions(options),
            })

            await window.timeclockWebAuthn.postJson(registerUrl, {
                ...window.timeclockWebAuthn.parseCredential(credential),
                alias: `${this.selectedFingerLabel} fingerprint - scan ${tap}`,
            })

            this.completedTaps = tap

            if (this.completedTaps < this.requiredTaps) {
                this.message = `Tap ${tap} read. Continue with tap ${tap + 1}.`
                return
            }

            this.success = true
            this.message = `${this.selectedFingerLabel} enrolled successfully.`
            this.registeredFingers = [
                ...new Set([
                    ...this.registeredFingers,
                    this.selectedFingerLabel,
                ]),
            ]
        } catch (error) {
            this.message =
                error instanceof Error
                    ? error.message
                    : 'Fingerprint enrollment failed.'
        } finally {
            this.loading = false
            this.activeTap = 0
        }
    },

    async removeSelectedFinger() {
        if (!this.selectedFingerRegistered || this.loading) return

        this.loading = true
        this.message = ''
        this.success = false

        try {
            const payload = await window.timeclockWebAuthn.postJson(
                deleteUrl,
                {
                    finger: this.selectedFingerLabel,
                },
                'DELETE',
            )

            this.registeredFingers = this.registeredFingers.filter(
                (finger) => finger !== this.selectedFingerLabel,
            )
            this.completedTaps = 0
            this.activeTap = 0
            this.success = true
            this.message =
                payload.message ||
                `${this.selectedFingerLabel} registration removed.`
        } catch (error) {
            this.message =
                error instanceof Error
                    ? error.message
                    : 'Unable to remove registered finger.'
        } finally {
            this.loading = false
        }
    },

    async startZktecoEnrollment() {
        this.zktecoLoading = true
        this.message = ''
        this.success = false
        const commandId =
            crypto.randomUUID?.() ??
            `zkteco-enroll-${Date.now()}-${Math.random().toString(36).slice(2)}`
        const employeePayload = {
            ...this.employee,
            command_id: commandId,
        }

        try {
            const response = await fetch(
                `${this.zktecoBridgeUrl.replace(/\/$/, '')}/enroll`,
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(employeePayload),
                },
            )

            const payload = await response.json().catch(() => ({}))

            if (!response.ok) {
                throw new Error(
                    payload.message || 'Unable to connect to ZKTeco Bridge.',
                )
            }

            this.success = true
            this.message =
                payload.message ||
                'ZKTeco Bridge is ready. Scan the same finger 3 times.'

            await this.pollZktecoEnrollmentStatus(commandId)
        } catch (error) {
            const launchUrl = `zkteco-bridge://enroll?payload=${encodeURIComponent(JSON.stringify(employeePayload))}`

            window.location.href = launchUrl

            this.message =
                'Opening ZKTeco Bridge. If your browser asks for permission, allow it, then scan the same finger 3 times.'

            await this.pollZktecoEnrollmentStatus(commandId)
        } finally {
            this.zktecoLoading = false
        }
    },

    async pollZktecoEnrollmentStatus(commandId) {
        const statusUrl = `${this.zktecoBridgeUrl.replace(/\/$/, '')}/status`
        const startedAt = Date.now()

        while (Date.now() - startedAt < 45000) {
            await new Promise((resolve) => setTimeout(resolve, 1000))

            const status = await fetch(statusUrl, {
                headers: {
                    Accept: 'application/json',
                },
            })
                .then((response) =>
                    response.ok ? response.json().catch(() => ({})) : null,
                )
                .catch(() => null)

            if (!status) continue
            if (status.command_id && status.command_id !== commandId) continue

            if (status.message) {
                this.message = status.message
            }

            if (status.state === 'success') {
                this.success = true
                this.message = 'Fingerprint successfully Registered!'
                setTimeout(() => {
                    document.dispatchEvent(
                        new KeyboardEvent('keydown', {
                            key: 'Escape',
                            bubbles: true,
                        }),
                    )
                }, 1200)
                return
            }

            if (status.state === 'error') {
                throw new Error(status.message || 'Fingerprint enrollment failed.')
            }
        }

        throw new Error('Fingerprint enrollment timed out.')
    },
})
