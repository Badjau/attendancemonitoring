import {
    enrollFace,
    faceEnrollmentStatus,
    faceServiceUrl,
} from './Services/faceService.js'

window.faceRegistration = ({ employeeId, hasRegisteredFace = false }) => ({
    video: null,
    captureCanvas: null,
    stream: null,
    statusText: 'Starting camera...',
    isCameraReady: false,
    isSubmitting: false,
    faceCount: 0,
    faceClear: false,
    capturedPreview: '',
    capturedBlob: null,
    isReviewingCapture: false,
    isCapturing: false,
    captureCountdown: 0,
    message: '',
    success: false,
    enrollmentCount: 0,
    requiredCount: 3,
    ready: false,
    isUpdatingExisting: hasRegisteredFace,
    hasResetExisting: false,
    serviceUrl: faceServiceUrl(),

    get canSave() {
        return (
            this.isReviewingCapture &&
            this.capturedBlob &&
            !this.isSubmitting &&
            this.isCameraReady
        )
    },

    get ovalStatusClass() {
        return this.isCameraReady
            ? 'border-success-300 shadow-[0_0_18px_rgba(34,197,94,0.5)]'
            : 'border-warning-300 shadow-[0_0_14px_rgba(251,191,36,0.35)]'
    },

    async init() {
        this.video = this.$refs.video
        this.captureCanvas = this.$refs.captureCanvas

        try {
            await this.loadStatus()
        } catch (error) {
            console.error(error)
            this.statusText =
                'Unable to connect to the face service. Check the face service URL, HTTPS certificate, and CORS allowed origins.'
            this.message = this.statusText
            this.success = false
            return
        }

        try {
            await this.startCamera()
            this.statusText = this.isUpdatingExisting
                ? `Capture 1 of ${this.requiredCount}.`
                : 'Capture a clear face image.'
        } catch (error) {
            console.error(error)
            this.statusText =
                error instanceof Error
                    ? error.message
                    : 'Unable to start face registration.'
            this.message = this.statusText
            this.success = false
        }
    },

    destroy() {
        this.stopCamera()
    },

    async loadStatus() {
        const status = await faceEnrollmentStatus(employeeId)
        this.requiredCount = status.required_count
        this.enrollmentCount = this.isUpdatingExisting ? 0 : status.enrollment_count
        this.ready = this.isUpdatingExisting ? false : status.ready
        this.statusText = this.isUpdatingExisting
            ? `Capture 1 of ${this.requiredCount}.`
            : this.statusText
    },

    async startCamera() {
        if (!navigator.mediaDevices?.getUserMedia) {
            throw new Error(
                'Camera access is not available in this browser or context.',
            )
        }

        this.stream = await navigator.mediaDevices.getUserMedia({
            video: {
                width: { ideal: 1280 },
                height: { ideal: 720 },
                facingMode: 'user',
            },
            audio: false,
        })

        this.video.srcObject = this.stream

        await new Promise((resolve) => {
            this.video.onloadedmetadata = () => {
                this.video.play()
                this.isCameraReady = true
                resolve()
            }
        })
    },

    stopCamera() {
        this.stream?.getTracks().forEach((track) => track.stop())
        this.stream = null
        this.isCameraReady = false
    },

    captureBlob() {
        if (!this.video || !this.captureCanvas) return null
        if (this.video.videoWidth <= 0 || this.video.videoHeight <= 0) {
            return null
        }

        this.captureCanvas.width = this.video.videoWidth
        this.captureCanvas.height = this.video.videoHeight

        const context = this.captureCanvas.getContext('2d')
        if (!context) return null

        context.drawImage(
            this.video,
            0,
            0,
            this.captureCanvas.width,
            this.captureCanvas.height,
        )
        this.capturedPreview = this.captureCanvas.toDataURL('image/jpeg', 0.9)

        const byteString = atob(this.capturedPreview.split(',')[1])
        const buffer = new Uint8Array(byteString.length)

        for (let i = 0; i < byteString.length; i++) {
            buffer[i] = byteString.charCodeAt(i)
        }

        return new Blob([buffer], { type: 'image/jpeg' })
    },

    async prepareCaptureForReview() {
        if (!this.isCameraReady || this.isCapturing || this.isSubmitting) return

        this.isCapturing = true
        this.message = ''
        this.success = false

        const countdownCompleted = await this.waitForCaptureCountdown()
        if (!countdownCompleted) {
            this.isCapturing = false
            return
        }

        const image = this.captureBlob()
        if (!image) {
            this.message = 'Camera image is not ready.'
            this.statusText = this.message
            this.success = false
            this.isCapturing = false
            return
        }

        this.capturedBlob = image
        this.faceCount = 1
        this.faceClear = true
        this.isReviewingCapture = true
        this.isCapturing = false
        this.statusText = 'Review the captured face image.'
        this.message = 'Save this capture or retake it.'
    },

    async waitForCaptureCountdown() {
        for (let seconds = 3; seconds > 0; seconds--) {
            this.captureCountdown = seconds
            this.statusText = `Hold still. Capturing in ${seconds}...`

            await new Promise((resolve) => setTimeout(resolve, 1000))

            if (
                !this.isCapturing ||
                this.isReviewingCapture ||
                this.isSubmitting ||
                !this.isCameraReady
            ) {
                this.captureCountdown = 0
                return false
            }
        }

        this.captureCountdown = 0
        return true
    },

    retake() {
        this.capturedPreview = ''
        this.capturedBlob = null
        this.isReviewingCapture = false
        this.isCapturing = false
        this.captureCountdown = 0
        this.message = ''
        this.success = false
        this.faceClear = false
        this.faceCount = 0
        this.statusText = 'Capture a clear face image.'
    },

    async save() {
        if (!this.capturedBlob) {
            this.message = 'Capture a face image before saving.'
            this.success = false
            return
        }

        this.isSubmitting = true
        this.message = ''
        this.statusText = 'Saving enrollment capture...'

        try {
            const shouldResetExisting = this.isUpdatingExisting && !this.hasResetExisting
            const payload = await enrollFace(
                employeeId,
                this.capturedBlob,
                '',
                shouldResetExisting,
            )

            this.enrollmentCount = payload.enrollment_count
            this.requiredCount = payload.required_count
            this.ready = payload.ready
            this.hasResetExisting = this.hasResetExisting || shouldResetExisting
            this.isUpdatingExisting = false
            this.success = true
            this.message = payload.message || 'Enrollment capture saved.'
            this.statusText = this.ready
                ? 'Face enrollment is ready. Save to finish.'
                : `Capture ${this.enrollmentCount + 1} of ${this.requiredCount}.`
            this.capturedBlob = null
            this.capturedPreview = ''
            this.isReviewingCapture = false
            this.faceClear = false
            this.faceCount = 0
            if (this.ready) this.stopCamera()
        } catch (error) {
            this.success = false
            this.message =
                error instanceof Error
                    ? error.message
                    : 'Face enrollment failed.'
            this.statusText = this.message
        } finally {
            this.isSubmitting = false
        }
    },

    finish() {
        this.stopCamera()

        const modal = this.$root.closest('[id^="fi-"][id*="-action-"]')

        if (modal?.id) {
            document.dispatchEvent(
                new CustomEvent('close-modal', {
                    bubbles: true,
                    composed: true,
                    detail: { id: modal.id },
                }),
            )
        }

        setTimeout(() => window.location.reload(), 350)
    },
})
