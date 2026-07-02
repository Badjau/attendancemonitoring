/**
 * @param {{ bridgeUrl?: string, scannerDeleteUrl?: string, employee?: Record<string, any>, registeredTemplates?: Array<{ id?: number, finger_index: number, label: string, enrolled_at?: string | null }> }} config
 */
window.fingerprintEnrollment = ({
    bridgeUrl = 'http://127.0.0.1:8765',
    scannerDeleteUrl = '',
    employee = {},
    registeredTemplates = [],
}) => ({
    maxRegisteredFingers: 3,
    zktecoLoading: false,
    submittingEnrollment: false,
    removingFingerIndex: null,
    enrollmentCaptured: false,
    enrollmentCommandId: '',
    enrollmentEvents: null,
    message: '',
    success: false,
    selectedFinger: null,
    fingers: [
        { index: 1, label: 'Left Thumb' },
        { index: 2, label: 'Left Index' },
        { index: 3, label: 'Left Middle' },
        { index: 4, label: 'Left Ring' },
        { index: 5, label: 'Left Little' },
        { index: 6, label: 'Right Thumb' },
        { index: 7, label: 'Right Index' },
        { index: 8, label: 'Right Middle' },
        { index: 9, label: 'Right Ring' },
        { index: 10, label: 'Right Little' },
    ],
    registeredTemplates: registeredTemplates.map((template) => ({
        ...template,
        finger_index: Number(template.finger_index),
    })),
    zktecoBridgeUrl: bridgeUrl,
    scannerDeleteUrl,
    employee,

    csrfToken() {
        return (
            document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content') || ''
        )
    },

    scannerMessage(message) {
        return String(message || '')
            .replaceAll('ZKTeco Bridge', 'Fingerprint scanner')
            .replaceAll('ZKTeco', 'Fingerprint')
    },

    shouldLaunchBridgeProtocol() {
        return /^https?:\/\/(?:127\.0\.0\.1|localhost)(?::8765)?(?:\/|$)/i.test(
            this.zktecoBridgeUrl,
        )
    },

    bridgeRequestOptions(body) {
        const headers = {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        }

        if (!this.shouldLaunchBridgeProtocol()) {
            headers['X-CSRF-TOKEN'] = this.csrfToken()
            headers['X-Requested-With'] = 'XMLHttpRequest'
        }

        return {
            method: 'POST',
            credentials: this.shouldLaunchBridgeProtocol()
                ? 'omit'
                : 'same-origin',
            headers,
            body: JSON.stringify(body),
        }
    },

    get busy() {
        return this.zktecoLoading || this.submittingEnrollment
    },

    get registeredFingerIndexes() {
        return this.registeredTemplates.map((template) =>
            Number(template.finger_index),
        )
    },

    get selectedFingerLabel() {
        return (
            this.fingers.find((finger) => finger.index === this.selectedFinger)
                ?.label || ''
        )
    },

    get selectedFingerRegistered() {
        return this.registeredFingerIndexes.includes(Number(this.selectedFinger))
    },

    get registrationLimitReached() {
        return this.registeredTemplates.length >= this.maxRegisteredFingers
    },

    get canScan() {
        return (
            !this.busy &&
            Boolean(this.selectedFinger) &&
            !this.selectedFingerRegistered &&
            !this.registrationLimitReached
        )
    },

    get canSubmit() {
        return (
            this.enrollmentCaptured &&
            Boolean(this.enrollmentCommandId) &&
            !this.busy
        )
    },

    isRegistered(finger) {
        return this.registeredFingerIndexes.includes(Number(finger.index))
    },

    resetPendingEnrollment() {
        this.enrollmentCaptured = false
        this.enrollmentCommandId = ''
        this.closeEnrollmentEvents()
    },

    closeEnrollmentEvents() {
        if (this.enrollmentEvents) {
            this.enrollmentEvents.close()
            this.enrollmentEvents = null
        }
    },

    listenToEnrollmentEvents(commandId) {
        this.closeEnrollmentEvents()

        const eventsUrl = `${this.zktecoBridgeUrl.replace(/\/$/, '')}/events?command_id=${encodeURIComponent(commandId)}`
        this.enrollmentEvents = new EventSource(eventsUrl)

        this.enrollmentEvents.onmessage = (event) => {
            this.handleEnrollmentEvent(JSON.parse(event.data || '{}'), commandId)
        }

        ;[
            'waiting_for_scan',
            'captured',
            'recording',
            'success',
            'error',
        ].forEach((state) => {
            this.enrollmentEvents.addEventListener(state, (event) => {
                this.handleEnrollmentEvent(
                    JSON.parse(event.data || '{}'),
                    commandId,
                )
            })
        })
    },

    handleEnrollmentEvent(status, commandId) {
        if (!status || (status.command_id && status.command_id !== commandId)) {
            return
        }

        if (status.message) {
            this.message = this.scannerMessage(status.message)
        }

        if (status.state === 'captured' && !this.enrollmentCaptured) {
            this.success = true
            this.enrollmentCaptured = true
            this.enrollmentCommandId = commandId
            this.zktecoLoading = false
            this.message =
                'Fingerprint captured. Click Save fingerprint to finish this registration.'
            return
        }

        if (status.state === 'success') {
            this.success = true
            this.zktecoLoading = false
            this.submittingEnrollment = false
            this.message = 'Fingerprint successfully Registered!'
            this.registeredTemplates = [
                ...this.registeredTemplates,
                {
                    finger_index: Number(this.selectedFinger),
                    label: this.selectedFingerLabel,
                    enrolled_at: new Intl.DateTimeFormat('en-US', {
                        month: 'short',
                        day: '2-digit',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit',
                    }).format(new Date()),
                },
            ]
            this.selectedFinger = null
            this.resetPendingEnrollment()
            return
        }

        if (status.state === 'error') {
            this.success = false
            this.zktecoLoading = false
            this.submittingEnrollment = false
            this.message = status.message || 'Fingerprint enrollment failed.'
            this.closeEnrollmentEvents()
        }
    },

    selectFinger(finger) {
        if (
            this.busy ||
            this.isRegistered(finger) ||
            this.registrationLimitReached
        ) {
            return
        }

        this.selectedFinger = finger.index
        this.message = ''
        this.success = false
        this.resetPendingEnrollment()
    },

    async removeRegisteredFinger(template) {
        if (!this.scannerDeleteUrl || this.busy || this.removingFingerIndex) {
            return
        }

        this.removingFingerIndex = Number(template.finger_index)
        this.message = ''
        this.success = false

        try {
            const response = await fetch(this.scannerDeleteUrl, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    finger_index: Number(template.finger_index),
                }),
            })

            const payload = await response.json().catch(() => ({}))

            if (!response.ok) {
                throw new Error(
                    payload.message || 'Unable to remove registered finger.',
                )
            }

            this.registeredTemplates = this.registeredTemplates.filter(
                (registeredTemplate) =>
                    Number(registeredTemplate.finger_index) !==
                    Number(template.finger_index),
            )

            if (this.selectedFinger === Number(template.finger_index)) {
                this.selectedFinger = null
            }

            this.resetPendingEnrollment()
            this.success = true
            this.message =
                payload.message || `${template.label} registration removed.`
        } catch (error) {
            this.message =
                error instanceof Error
                    ? error.message
                    : 'Unable to remove registered finger.'
        } finally {
            this.removingFingerIndex = null
        }
    },

    async startScannerEnrollment() {
        if (!this.selectedFinger) {
            this.message = 'Select which finger to register first.'
            this.success = false
            return
        }

        if (this.selectedFingerRegistered) {
            this.message = `${this.selectedFingerLabel} is already registered.`
            this.success = false
            return
        }

        if (this.registrationLimitReached) {
            this.message =
                'This employee already has 3 registered fingers. Remove one before registering another.'
            this.success = false
            return
        }

        this.zktecoLoading = true
        this.message = ''
        this.success = false
        this.resetPendingEnrollment()

        const commandId =
            crypto.randomUUID?.() ??
            `fingerprint-enroll-${Date.now()}-${Math.random().toString(36).slice(2)}`
        const employeePayload = {
            ...this.employee,
            command_id: commandId,
            finger_index: this.selectedFinger,
        }

        try {
            const response = await fetch(
                `${this.zktecoBridgeUrl.replace(/\/$/, '')}/commands/enroll`,
                this.bridgeRequestOptions(employeePayload),
            )

            const payload = await response.json().catch(() => ({}))

            if (!response.ok) {
                throw new Error(
                    payload.message ||
                        'Unable to connect to the fingerprint scanner.',
                )
            }

            this.message = this.scannerMessage(
                payload.message ||
                    `Waiting for ${this.selectedFingerLabel}. Scan the same finger 3 times.`,
            )
            this.listenToEnrollmentEvents(commandId)
        } catch (error) {
            if (!this.shouldLaunchBridgeProtocol()) {
                this.message =
                    error instanceof Error
                        ? error.message
                        : 'Unable to connect to the fingerprint scanner.'
                this.success = false
                return
            }

            const launchUrl = `zkteco-bridge://enroll?payload=${encodeURIComponent(JSON.stringify(employeePayload))}`

            window.location.href = launchUrl

            this.message = `Opening the fingerprint scanner. Scan ${this.selectedFingerLabel} 3 times when it is ready.`
            this.listenToEnrollmentEvents(commandId)
        } finally {
            if (!this.enrollmentEvents) {
                this.zktecoLoading = false
            }
        }
    },

    async submitEnrollment() {
        if (!this.canSubmit) return

        this.submittingEnrollment = true
        this.message = ''
        this.success = false

        try {
            const response = await fetch(
                `${this.zktecoBridgeUrl.replace(/\/$/, '')}/commands/${encodeURIComponent(this.enrollmentCommandId)}/commit-enrollment`,
                this.bridgeRequestOptions({}),
            )

            const payload = await response.json().catch(() => ({}))

            if (!response.ok) {
                throw new Error(
                    payload.message || 'Unable to save registered fingerprint.',
                )
            }

            this.message = payload.message || 'Saving fingerprint...'
        } catch (error) {
            this.message =
                error instanceof Error
                    ? error.message
                    : 'Unable to save registered fingerprint.'
        } finally {
            this.submittingEnrollment = false
        }
    },

})
