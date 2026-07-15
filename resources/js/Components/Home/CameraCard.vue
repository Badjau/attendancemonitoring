<script setup lang="ts">
import axios from 'axios'
import {
    Camera,
    Delete,
    Eraser,
    LoaderCircle,
    LogIn,
    LogOut,
    MapPin,
    TriangleAlert,
} from '@lucide/vue'
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useToast } from 'primevue'
import { useGeolocator } from '@/Composables/useGeolocator.js'
import { useSyncStore } from '@/Stores/sync.js'
import { detectFaces, recognizeFace, verifyEmployeeFace } from '@/Services/faceService.js'

type AttendanceAction = 'time-in' | 'time-out'
type AttendanceMethod = 'rfid' | 'keypad' | 'fingerprint' | 'face'
type AttendanceGreeting = {
    first_name: string
    is_birthday: boolean
    attendance_type: AttendanceAction
}
type VerifiedEmployee = {
    id: number
    employee_id: string
    first_name: string
    last_name: string
    position: string
    branch?: string | null
    profile_url?: string | null
}
type LiveFaceMatch = {
    employee: VerifiedEmployee
    image: string
}
type AttendanceSchedule = {
    time_in_start: string
    time_out_start: string
    duplicate_scan_window_seconds: string
    face_capture_width_ratio: string
    face_capture_height_ratio: string
    show_face_attendance_button: boolean
}
type ScanStatusMessages = {
    idle: string
    rfid_not_recognized: string
    fingerprint_waiting: string
    fingerprint_not_found: string
    fingerprint_matched: string
    attendance_recorded: string
}
type ScannerStatusTone = 'default' | 'error'

const props = defineProps<{
    employees: VerifiedEmployee[]
    attendanceSchedule: AttendanceSchedule
    scanStatusMessages: ScanStatusMessages
    zktecoBridgeUrl: string
}>()

const emit = defineEmits<{
    employeeVerified: [employee: VerifiedEmployee]
}>()

const toast = useToast()
const syncStore = useSyncStore()
const {
    coords,
    error: locationError,
    loading: locationLoading,
    accuracyWarning,
    address,
    usingCachedLocation,
    locationSource,
    getLocation,
} = useGeolocator()

const attendanceType = ref<AttendanceAction | ''>('')
const videoRef = ref<HTMLVideoElement | null>(null)
const overlayRef = ref<HTMLCanvasElement | null>(null)
const canvasRef = ref<HTMLCanvasElement | null>(null)
const isLoading = ref(false)
const processingMethod = ref<AttendanceMethod | ''>('')
const isError = ref(false)
const isVideoReady = ref(false)
const isCameraActive = ref(false)
const isSilentCameraCapture = ref(false)
const faceStatusText = ref('Face verification ready.')
const scannerStatusText = ref(props.scanStatusMessages.idle)
const scannerStatusTone = ref<ScannerStatusTone>('default')

const currentTime = ref('')
const currentDate = ref('')

const showEmployeeIdInputField = ref(true)

const rfidInput = ref<HTMLInputElement | null>(null)
const empIdInput = ref<HTMLInputElement | null>(null)
const rfidBuffer = ref('')
const employeePassword = ref('')
const hasTypedEmployeePassword = ref(false)
const keypadDigits = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0']

let stream: MediaStream | null = null
let interval: ReturnType<typeof setInterval>
let clockSyncInterval: ReturnType<typeof setInterval>
let focusInterval: ReturnType<typeof setInterval>
let faceDetectionInterval: ReturnType<typeof setInterval> | null = null
let liveFaceDetectionInterval: ReturnType<typeof setInterval> | null = null
let autoFingerprintTimeout: ReturnType<typeof setTimeout> | null = null
let scannerStatusResetTimeout: ReturnType<typeof setTimeout> | null = null
let rfidTimeout: any = null
let isAutoFingerprintScanActive = false
let autoFingerprintScanVersion = 0
let zktecoEvents: EventSource | null = null
let scannerStatusHoldUntil = 0
let rfidScanStartedAt = 0
let rfidLastKeyAt = 0
let rfidKeyCount = 0

const lastScannedTime = ref(0)
const SCAN_COOLDOWN_MS = 1000
const RFID_SCAN_MIN_LENGTH = 6
const RFID_SCAN_IDLE_RESET_MS = 120
const RFID_SCAN_MAX_TOTAL_MS = 350
const RFID_SCAN_MAX_AVG_KEY_MS = 45
const AUTO_FINGERPRINT_RETRY_MS = 500
const AUTO_FINGERPRINT_SCAN_WINDOW_MS = 120000
const CLOCK_SYNC_INTERVAL_MS = 60 * 60 * 1000
const CLOCK_OFFSET_STORAGE_KEY = 'timeclock.clockOffsetMs'
const CLOCK_TIME_ZONE = 'Asia/Manila'
const ATTENDANCE_IMAGE_MAX_WIDTH = 960
const FACE_MULTI_WARNING_MESSAGE =
    'Multiple faces detected. Please step out of the camera view.'
const FACE_WAITING_MESSAGE = 'Waiting for face...'
const FACE_DETECTION_POLL_MS = 900
const FACE_WAIT_TIMEOUT_MS = 6000
const FACE_WAIT_RETRY_MS = 350
const FACE_CAPTURE_STABILIZE_MS = 1500
let clockOffsetMs = 0
const ratioSetting = (value: string | number | undefined, fallback: number): number => {
    const ratio = Number(value)

    if (!Number.isFinite(ratio)) return fallback

    return Math.max(0.25, Math.min(1, ratio))
}
const faceActiveZoneWidthRatio = computed(() =>
    ratioSetting(props.attendanceSchedule.face_capture_width_ratio, 0.5),
)
const faceActiveZoneHeightRatio = computed(() =>
    ratioSetting(props.attendanceSchedule.face_capture_height_ratio, 0.68),
)
const hasMultipleFacesInView = ref(false)
const liveFaceCount = ref(0)
const cameraGuidanceMessage = ref('')
const isLocationReady = computed(
    () =>
        Boolean(coords.value) &&
        Number.isFinite(coords.value.latitude) &&
        Number.isFinite(coords.value.longitude) &&
        !locationError.value,
)
const showCamera = computed(
    () => isCameraActive.value && !isSilentCameraCapture.value,
)
const isProcessing = computed(() => Boolean(processingMethod.value))
const processingLabel = computed(() =>
    faceStatusText.value && faceStatusText.value !== 'Face verification ready.'
        ? faceStatusText.value
        : 'Processing, please wait...',
)
const showFaceCheckOnly = computed(() =>
    isProcessing.value &&
    isCameraActive.value &&
    faceStatusText.value.startsWith('Checking face for '),
)
const cameraGuidanceText = computed(() => {
    if (cameraGuidanceMessage.value === FACE_WAITING_MESSAGE) {
        return 'Look straight into the camera. Waiting for face...'
    }

    if (cameraGuidanceMessage.value) {
        return cameraGuidanceMessage.value
    }

    return 'Look straight into the camera to record your attendance.'
})
const cameraGuidanceIsWarning = computed(() =>
    [FACE_MULTI_WARNING_MESSAGE, 'No face detected.'].includes(
        cameraGuidanceMessage.value,
    ),
)
const scanStatusMessage = (key: keyof ScanStatusMessages): string =>
    props.scanStatusMessages[key]
const setScannerStatus = (
    key: keyof ScanStatusMessages,
    options: { tone?: ScannerStatusTone; force?: boolean } = {},
) => {
    if (
        !options.force &&
        Date.now() < scannerStatusHoldUntil &&
        scannerStatusTone.value === 'error'
    ) {
        return
    }

    if (scannerStatusResetTimeout) {
        clearTimeout(scannerStatusResetTimeout)
        scannerStatusResetTimeout = null
    }

    scannerStatusHoldUntil = 0
    scannerStatusText.value = scanStatusMessage(key)
    scannerStatusTone.value = options.tone ?? 'default'
}
const setTemporaryScannerError = (
    key: 'rfid_not_recognized' | 'fingerprint_not_found',
) => {
    if (scannerStatusResetTimeout) {
        clearTimeout(scannerStatusResetTimeout)
    }

    scannerStatusHoldUntil = Date.now() + 5000
    scannerStatusText.value = scanStatusMessage(key)
    scannerStatusTone.value = 'error'
    scannerStatusResetTimeout = setTimeout(() => {
        scannerStatusHoldUntil = 0
        scannerStatusText.value = scanStatusMessage('idle')
        scannerStatusTone.value = 'default'
        scannerStatusResetTimeout = null
    }, 5000)
}

const employeeFullName = (employee: VerifiedEmployee): string =>
    `${employee.first_name} ${employee.last_name}`.trim()

const locationLabel = (): string => {
    const currentCoords = coords.value as any

    if (address.value || currentCoords.address) {
        return address.value || currentCoords.address
    }

    if (
        Number.isFinite(currentCoords.latitude) &&
        Number.isFinite(currentCoords.longitude)
    ) {
        return `${currentCoords.latitude.toFixed(6)}, ${currentCoords.longitude.toFixed(6)}`
    }

    return ''
}

const initializeCamera = async () => {
    try {
        if (stream && isVideoReady.value) return

        if (!navigator.mediaDevices?.getUserMedia) {
            throw new Error(
                'getUserMedia is not available in this browser/context.',
            )
        }

        isError.value = false
        isLoading.value = true

        stream = await navigator.mediaDevices.getUserMedia({
            video: {
                width: { ideal: 960 },
                height: { ideal: 540 },
                facingMode: 'user',
            },
            audio: false,
        })

        if (!videoRef.value) return

        videoRef.value.srcObject = stream
        await new Promise<void>((resolve) => {
            videoRef.value!.onloadedmetadata = async () => {
                await videoRef.value?.play()
                isLoading.value = false
                isVideoReady.value = true
                resolve()
            }
        })
    } catch (error) {
        console.error('Camera error:', error)
        isLoading.value = false
        isError.value = true
    }
}

const waitForCameraFrame = async (timeoutMs = 2500): Promise<boolean> => {
    const startedAt = Date.now()

    while (Date.now() - startedAt < timeoutMs) {
        const video = videoRef.value

        if (
            video &&
            isVideoReady.value &&
            video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA &&
            video.videoWidth > 0 &&
            video.videoHeight > 0
        ) {
            return true
        }

        await new Promise<void>((resolve) =>
            requestAnimationFrame(() => resolve()),
        )
    }

    return false
}

const stopCamera = (): any => {
    stopLiveFaceDetection()
    stream?.getTracks().forEach((track) => track.stop())
    stream = null
    if (videoRef.value) videoRef.value.srcObject = null
    clearFaceDetectorOverlay()
    isVideoReady.value = false
    isCameraActive.value = false
    isSilentCameraCapture.value = false
}

const clearFaceDetectorOverlay = () => {
    if (faceDetectionInterval) {
        clearInterval(faceDetectionInterval)
        faceDetectionInterval = null
    }

    const canvas = overlayRef.value
    const context = canvas?.getContext('2d')
    if (canvas && context) context.clearRect(0, 0, canvas.width, canvas.height)
}

const stopLiveFaceDetection = () => {
    if (liveFaceDetectionInterval) {
        clearInterval(liveFaceDetectionInterval)
        liveFaceDetectionInterval = null
    }

    hasMultipleFacesInView.value = false
    liveFaceCount.value = 0
    cameraGuidanceMessage.value = ''
}

const clearAutoFingerprintScan = () => {
    if (autoFingerprintTimeout) {
        clearTimeout(autoFingerprintTimeout)
        autoFingerprintTimeout = null
    }
}

const closeZktecoEvents = () => {
    zktecoEvents?.close()
    zktecoEvents = null
}

const pauseAutoFingerprintScan = () => {
    autoFingerprintScanVersion++
    clearAutoFingerprintScan()
    closeZktecoEvents()
    isAutoFingerprintScanActive = false
}

const startFaceDetectorOverlay = () => {
    clearFaceDetectorOverlay()

    const drawOverlay = () => {
        const canvas = overlayRef.value
        const video = videoRef.value
        const context = canvas?.getContext('2d')
        if (!canvas || !video || !context) return

        const rect = video.getBoundingClientRect()
        const ratio = window.devicePixelRatio || 1
        canvas.width = Math.round(rect.width * ratio)
        canvas.height = Math.round(rect.height * ratio)
        canvas.style.width = `${rect.width}px`
        canvas.style.height = `${rect.height}px`
        context.setTransform(ratio, 0, 0, ratio, 0, 0)
        context.clearRect(0, 0, rect.width, rect.height)

        const zoneWidth = rect.width * faceActiveZoneWidthRatio.value
        const zoneHeight = rect.height * faceActiveZoneHeightRatio.value
        const zoneX = (rect.width - zoneWidth) / 2
        const zoneY = (rect.height - zoneHeight) / 2

        context.fillStyle = 'rgba(0, 30, 29, 0.42)'
        context.fillRect(0, 0, rect.width, zoneY)
        context.fillRect(0, zoneY + zoneHeight, rect.width, rect.height - zoneY - zoneHeight)
        context.fillRect(0, zoneY, zoneX, zoneHeight)
        context.fillRect(zoneX + zoneWidth, zoneY, rect.width - zoneX - zoneWidth, zoneHeight)

        context.strokeStyle = '#f9bc60'
        context.lineWidth = 3
        context.setLineDash([14, 8])
        context.strokeRect(zoneX, zoneY, zoneWidth, zoneHeight)
        context.setLineDash([])
    }

    drawOverlay()
    faceDetectionInterval = setInterval(drawOverlay, 250)
}

const captureImage = (): string | null => {
    if (!videoRef.value || !canvasRef.value || !isVideoReady.value) return null

    const video = videoRef.value
    const canvas = canvasRef.value

    if (video.videoWidth <= 0 || video.videoHeight <= 0) return null

    const sourceWidth = Math.round(video.videoWidth * faceActiveZoneWidthRatio.value)
    const sourceHeight = Math.round(video.videoHeight * faceActiveZoneHeightRatio.value)
    const sourceX = Math.round((video.videoWidth - sourceWidth) / 2)
    const sourceY = Math.round((video.videoHeight - sourceHeight) / 2)
    const scale = Math.min(1, ATTENDANCE_IMAGE_MAX_WIDTH / sourceWidth)

    canvas.width = Math.round(sourceWidth * scale)
    canvas.height = Math.round(sourceHeight * scale)

    const ctx = canvas.getContext('2d')
    if (!ctx) return null

    ctx.drawImage(
        video,
        sourceX,
        sourceY,
        sourceWidth,
        sourceHeight,
        0,
        0,
        canvas.width,
        canvas.height,
    )

    return canvas.toDataURL('image/jpeg', 0.72)
}

const checkLiveFaceCount = async (): Promise<number | null> => {
    if (!isCameraActive.value || !isVideoReady.value || isSilentCameraCapture.value) {
        return null
    }

    const image = captureImage()
    if (!image) return null

    try {
        const result = await detectFaces(base64ToBlob(image, 'image/jpeg'))
        const faceCount = Number(result.face_count || 0)
        const hasMultipleFaces = faceCount > 1
        liveFaceCount.value = faceCount
        hasMultipleFacesInView.value = hasMultipleFaces

        if (hasMultipleFaces) {
            cameraGuidanceMessage.value = result.message || FACE_MULTI_WARNING_MESSAGE
            return faceCount
        }

        if (faceCount === 0) {
            cameraGuidanceMessage.value = FACE_WAITING_MESSAGE
            return faceCount
        }

        cameraGuidanceMessage.value = ''

        return faceCount
    } catch (error) {
        console.error('Face detection failed:', error)
        return null
    }
}

const startLiveFaceDetection = () => {
    stopLiveFaceDetection()
    void checkLiveFaceCount()
    liveFaceDetectionInterval = setInterval(() => {
        void checkLiveFaceCount()
    }, FACE_DETECTION_POLL_MS)
}

const ensureSingleFaceInView = async (): Promise<boolean> => {
    const startedAt = Date.now()

    while (Date.now() - startedAt < FACE_WAIT_TIMEOUT_MS) {
        const faceCount = await checkLiveFaceCount()

        if (faceCount === 1) {
            cameraGuidanceMessage.value = 'Hold still...'
            await new Promise((resolve) =>
                setTimeout(resolve, FACE_CAPTURE_STABILIZE_MS),
            )

            const stableFaceCount = await checkLiveFaceCount()

            if (stableFaceCount === 1) return true

            if (stableFaceCount !== null && stableFaceCount > 1) {
                toast.add({
                    severity: 'warn',
                    summary: 'Face Verification',
                    detail: FACE_MULTI_WARNING_MESSAGE,
                    life: 4000,
                })
                cameraGuidanceMessage.value = FACE_MULTI_WARNING_MESSAGE

                return false
            }

            cameraGuidanceMessage.value = FACE_WAITING_MESSAGE
            continue
        }

        if (faceCount !== null && faceCount > 1) {
            toast.add({
                severity: 'warn',
                summary: 'Face Verification',
                detail: FACE_MULTI_WARNING_MESSAGE,
                life: 4000,
            })
            cameraGuidanceMessage.value = FACE_MULTI_WARNING_MESSAGE

            return false
        }

        cameraGuidanceMessage.value = FACE_WAITING_MESSAGE
        await new Promise((resolve) => setTimeout(resolve, FACE_WAIT_RETRY_MS))
    }

    toast.add({
        severity: 'warn',
        summary: 'Face Verification',
        detail: 'No face detected. Look straight at the camera and try again.',
        life: 4000,
    })
    cameraGuidanceMessage.value = 'No face detected.'

    return false
}

const loadClockOffset = () => {
    const storedOffset = Number(localStorage.getItem(CLOCK_OFFSET_STORAGE_KEY))

    clockOffsetMs = Number.isFinite(storedOffset) ? storedOffset : 0
}

const trustedNow = (): Date => new Date(Date.now() + clockOffsetMs)

const trustedNowIso = (): string => trustedNow().toISOString()

const syncClock = async () => {
    if (!navigator.onLine) return

    const startedAt = Date.now()
    const response = await fetch('/attendance/current-time', {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    })
    const endedAt = Date.now()

    if (!response.ok) return

    const payload = await response.json()
    const serverTimestamp = Number(payload.timestamp_ms)

    if (!Number.isFinite(serverTimestamp)) return

    clockOffsetMs = Math.round(serverTimestamp - (startedAt + endedAt) / 2)
    localStorage.setItem(CLOCK_OFFSET_STORAGE_KEY, String(clockOffsetMs))
    updateTime()
}

const syncClockQuietly = () => {
    syncClock().catch(() => null)
}

const updateTime = () => {
    const now = trustedNow()

    currentTime.value = now.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
        timeZone: CLOCK_TIME_ZONE,
    })

    currentDate.value = now.toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        timeZone: CLOCK_TIME_ZONE,
    })
}

const ensureAttendanceFlowReady = async (actionName?: AttendanceAction) => {
    if (actionName) {
        attendanceType.value = actionName
    }

    showEmployeeIdInputField.value = true
    await nextTick()
}

const openCameraForCapture = async (
    options: { loadFaceVerification?: boolean; silent?: boolean } = {},
) => {
    const loadFaceVerification = options.loadFaceVerification ?? true

    showEmployeeIdInputField.value = true
    isCameraActive.value = true
    isSilentCameraCapture.value = options.silent ?? !loadFaceVerification
    await nextTick()
    await initializeCamera()

    const hasFrame = await waitForCameraFrame()
    if (!hasFrame) {
        throw new Error('Camera opened, but no video frame was ready.')
    }

    if (!loadFaceVerification) {
        clearFaceDetectorOverlay()
        return
    }

    faceStatusText.value = 'Face camera ready.'
    startFaceDetectorOverlay()
    startLiveFaceDetection()
}

const handleTimeAction = async (actionName: AttendanceAction) => {
    if (attendanceType.value === actionName) {
        resetAttendanceSelection()
        return
    }

    pauseAutoFingerprintScan()
    await ensureAttendanceFlowReady(actionName)
    employeePassword.value = ''
    hasTypedEmployeePassword.value = false
    setScannerStatus('idle')
    scheduleAutoFingerprintScan(0)
}

const resetAttendanceSelection = (resetScannerStatus = true) => {
    attendanceType.value = ''
    showEmployeeIdInputField.value = true
    employeePassword.value = ''
    hasTypedEmployeePassword.value = false
    isLoading.value = false
    processingMethod.value = ''
    faceStatusText.value = 'Face verification ready.'
    if (resetScannerStatus) {
        setScannerStatus('idle')
    }
    stopCamera()
    setTimeout(() => forceRFIDFocus(), 50)
    scheduleAutoFingerprintScan()
}

const startProcessing = (method: AttendanceMethod, message: string) => {
    processingMethod.value = method
    isLoading.value = true
    faceStatusText.value = message
}

const stopProcessing = () => {
    processingMethod.value = ''
    isLoading.value = false
}

const announceAttendanceGreeting = (greeting?: AttendanceGreeting) => {
    if (!greeting?.first_name) return

    window.dispatchEvent(
        new CustomEvent('attendance:greeting', {
            detail: greeting,
        }),
    )
}

const announceAttendanceRecorded = (result: any) => {
    window.dispatchEvent(
        new CustomEvent('attendance:recorded', {
            detail: result,
        }),
    )
}

const focusRFID = () => {
    if (!rfidInput.value) return
    if (document.activeElement === empIdInput.value) return

    try {
        rfidInput.value.focus()
    } catch (e) {
        console.error('Error focusing RFID input:', e)
    }
}

const ensureRFIDFocus = () => {
    if (document.activeElement === empIdInput.value) return

    try {
        if (rfidInput.value && document.activeElement !== rfidInput.value) {
            rfidInput.value.focus()
        }
    } catch {
        // Silently fail
    }
}

const forceRFIDFocus = () => {
    try {
        rfidInput.value?.focus?.()
    } catch {
        // Silently fail
    }
}

const resetRFIDScanCapture = () => {
    rfidScanStartedAt = 0
    rfidLastKeyAt = 0
    rfidKeyCount = 0
    rfidBuffer.value = ''
    if (rfidTimeout) clearTimeout(rfidTimeout)
    if (rfidInput.value) rfidInput.value.value = ''
}

const trackRFIDKeystroke = (e: KeyboardEvent) => {
    if (e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) return

    const now = performance.now()

    if (
        !rfidScanStartedAt ||
        (rfidLastKeyAt && now - rfidLastKeyAt > RFID_SCAN_IDLE_RESET_MS)
    ) {
        rfidScanStartedAt = now
        rfidKeyCount = 0
        rfidBuffer.value = ''
        if (rfidTimeout) clearTimeout(rfidTimeout)
        if (rfidInput.value) rfidInput.value.value = ''
    }

    rfidLastKeyAt = now
    rfidKeyCount += 1
}

const isLikelyRFIDScan = (data: string) => {
    if (data.length < RFID_SCAN_MIN_LENGTH || rfidKeyCount < RFID_SCAN_MIN_LENGTH) {
        return false
    }

    const duration = rfidLastKeyAt - rfidScanStartedAt
    const averageKeyMs = duration / Math.max(rfidKeyCount - 1, 1)

    return (
        duration <= RFID_SCAN_MAX_TOTAL_MS ||
        averageKeyMs <= RFID_SCAN_MAX_AVG_KEY_MS
    )
}

const onRFIDInput = () => {
    const data = rfidInput.value?.value.trim()

    if (data && data.length > 0) {
        if (!isLikelyRFIDScan(data)) {
            if (rfidTimeout) clearTimeout(rfidTimeout)
            return
        }

        rfidBuffer.value = data

        if (rfidTimeout) clearTimeout(rfidTimeout)

        rfidTimeout = setTimeout(() => {
            submitRFIDAttendance(rfidBuffer.value)
        }, 50)
    }
}

const onRFIDKeydown = (e: KeyboardEvent) => {
    trackRFIDKeystroke(e)

    if (e.key === 'Enter') {
        e.preventDefault()

        if (rfidTimeout) clearTimeout(rfidTimeout)

        const scannedRfid = (rfidBuffer.value || rfidInput.value?.value || '').trim()

        if (!isLikelyRFIDScan(scannedRfid)) {
            resetRFIDScanCapture()
            return
        }

        submitRFIDAttendance(scannedRfid)
    }
}

const onEmpIdFocus = (e: FocusEvent) => {
    const el = e.target as HTMLInputElement | null
    el?.select?.()
}

const onEmpIdInput = () => {
    employeePassword.value = employeePassword.value.replace(/\D/g, '')
    hasTypedEmployeePassword.value = true
}

const onEmpIdKeydown = (e: KeyboardEvent) => {
    if (e.key !== 'Enter') return
    e.preventDefault()

    submitManualAttendance()
}

const appendKeypadDigit = (digit: string) => {
    employeePassword.value += digit
    hasTypedEmployeePassword.value = true
}

const deleteKeypadDigit = () => {
    employeePassword.value = employeePassword.value.slice(0, -1)
    hasTypedEmployeePassword.value = true
}

const clearKeypad = () => {
    employeePassword.value = ''
    hasTypedEmployeePassword.value = true
}

const csrfToken = (): string =>
    document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') || ''

const decodeWebAuthn = (input: string): Uint8Array => {
    input = input.replace(/-/g, '+').replace(/_/g, '/')
    const pad = input.length % 4
    if (pad) input += '='.repeat(4 - pad)

    return Uint8Array.from(atob(input), (char) => char.charCodeAt(0))
}

const encodeWebAuthn = (buffer: ArrayBuffer): string =>
    btoa(String.fromCharCode(...new Uint8Array(buffer)))

const parseWebAuthnOptions = (
    publicKey: any,
): PublicKeyCredentialRequestOptions | PublicKeyCredentialCreationOptions => {
    publicKey.challenge = decodeWebAuthn(publicKey.challenge)

    if (publicKey.user?.id) {
        publicKey.user.id = decodeWebAuthn(publicKey.user.id)
    }

    for (const key of ['excludeCredentials', 'allowCredentials']) {
        if (!publicKey[key]) continue

        publicKey[key] = publicKey[key].map((credential: any) => ({
            ...credential,
            id: decodeWebAuthn(credential.id),
        }))
    }

    return publicKey
}

const parseWebAuthnCredential = (credential: any): any => {
    const response: Record<string, string> = {}

    for (const key of [
        'clientDataJSON',
        'attestationObject',
        'authenticatorData',
        'signature',
        'userHandle',
    ]) {
        if (credential.response[key]) {
            response[key] = encodeWebAuthn(credential.response[key])
        }
    }

    return {
        id: credential.id,
        rawId: encodeWebAuthn(credential.rawId),
        type: credential.type,
        authenticatorAttachment: credential.authenticatorAttachment,
        clientExtensionResults: credential.getClientExtensionResults(),
        response,
    }
}

const postWebAuthnJson = async (
    url: string,
    data: Record<string, any> = {},
): Promise<any> => {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(data),
    })

    const payload = await response.json().catch(() => ({}))

    if (!response.ok) {
        throw new Error(
            payload.message ||
                Object.values(payload.errors ?? {})?.[0]?.[0] ||
                'Fingerprint verification failed.',
        )
    }

    return payload
}

const captureAttendanceImage = (): string | null => {
    const image = captureImage()

    if (!image) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'Camera not ready. Please allow camera access.',
            life: 5000,
        })

        return null
    }

    return image
}

const normalizeEmployeeCode = (employeeId: unknown): string =>
    String(employeeId ?? '').trim()

const verifyEmployeeIdentifier = async (
    employeeIdentifier: string,
    method: AttendanceMethod,
): Promise<VerifiedEmployee | null> => {
    try {
        faceStatusText.value = 'Checking employee...'

        const response = await axios.post('/attendance/verify-employee', {
            _token: csrfToken(),
            employee_id: employeeIdentifier,
            attendance_method: method,
        })

        const employee = response.data.employee as VerifiedEmployee
        emit('employeeVerified', employee)

        return employee
    } catch (error: any) {
        const responseMessage =
            error?.response?.data?.message ??
            Object.values(error?.response?.data?.errors ?? {})?.[0]?.[0] ??
            'Employee is not existing.'
        const message =
            method === 'rfid'
                ? scanStatusMessage('rfid_not_recognized')
                : responseMessage

        toast.add({
            severity: 'error',
            summary: 'Employee',
            detail: message,
            life: 5000,
        })

        if (method === 'rfid') {
            setTemporaryScannerError('rfid_not_recognized')
        } else if (method === 'fingerprint') {
            setTemporaryScannerError('fingerprint_not_found')
        } else {
            faceStatusText.value = message
        }
        resetAttendanceSelection(method !== 'rfid' && method !== 'fingerprint')

        return null
    }
}

const verifyLiveFaceMatchesEmployee = async (
    employee: VerifiedEmployee,
): Promise<LiveFaceMatch | null> => {
    if (!videoRef.value || !isVideoReady.value) {
        toast.add({
            severity: 'error',
            summary: 'Camera',
            detail: 'Camera not ready. Please allow camera access.',
            life: 3000,
        })
        return null
    }

    try {
        faceStatusText.value = `Checking face for ${employeeFullName(employee)}...`
        if (!(await ensureSingleFaceInView())) {
            return null
        }

        const image = captureImage()
        if (!image) {
            throw new Error('Camera photo could not be captured.')
        }

        const result = await verifyEmployeeFace(
            employee.employee_id,
            base64ToBlob(image, 'image/jpeg'),
        )
        if (
            !result.matched ||
            normalizeEmployeeCode(result.employee_id) !==
                normalizeEmployeeCode(employee.employee_id)
        ) {
            toast.add({
                severity: 'error',
                summary: 'Face Verification',
                detail: `Face does not match ${employeeFullName(employee)}.`,
                life: 6000,
            })
            faceStatusText.value = result.message || 'Face mismatch.'
            return null
        }

        faceStatusText.value = `Face matched ${employeeFullName(employee)}.`
        return {
            employee,
            image,
        }
    } catch (error) {
        console.error('Face verification failed:', error)
        toast.add({
            severity: 'error',
            summary: 'Face Verification',
            detail: 'Unable to verify face. Check lighting and registered face image.',
            life: 6000,
        })
        faceStatusText.value = 'Unable to verify face.'
        return null
    }
}

const verifyEmployeeAndSubmit = async (
    employeeIdentifier: string,
    method: AttendanceMethod,
): Promise<boolean> => {
    const employee = await verifyEmployeeIdentifier(employeeIdentifier, method)
    if (!employee) return false

    await openCameraForCapture({ loadFaceVerification: true })

    const matchedFace = await verifyLiveFaceMatchesEmployee(employee)
    if (!matchedFace) {
        resetAttendanceSelection()
        return false
    }

    await submitAttendance(
        employee.employee_id,
        method,
        employeeFullName(employee),
        matchedFace.image,
    )

    return true
}

const submitFaceAttendance = async () => {
    pauseAutoFingerprintScan()

    try {
        startProcessing('face', 'Opening face recognition...')
        await openCameraForCapture({ loadFaceVerification: true })

        if (!(await ensureSingleFaceInView())) {
            resetAttendanceSelection()
            return
        }

        const image = captureAttendanceImage()
        if (!image) {
            resetAttendanceSelection()
            return
        }

        faceStatusText.value = 'Recognizing face...'
        const result = await recognizeFace(base64ToBlob(image, 'image/jpeg'))

        if (!result.matched || !result.employee_id) {
            toast.add({
                severity: 'error',
                summary: 'Face Recognition',
                detail: result.message || 'No registered face matched.',
                life: 6000,
            })
            faceStatusText.value = result.message || 'No registered face matched.'
            resetAttendanceSelection()
            return
        }

        const employee = await verifyEmployeeIdentifier(result.employee_id, 'face')
        if (!employee) return

        await submitAttendance(
            employee.employee_id,
            'face',
            employeeFullName(employee),
            image,
        )
    } catch (error) {
        console.error('Error submitting face attendance:', error)
        toast.add({
            severity: 'error',
            summary: 'Face Recognition',
            detail:
                error instanceof Error
                    ? error.message
                    : 'Unable to record face attendance.',
            life: 6000,
        })
        stopProcessing()
        resetAttendanceSelection()
    }
}

const submitRFIDAttendance = async (rfid: any) => {
    pauseAutoFingerprintScan()

    const scannedRfid = rfid?.trim()

    resetRFIDScanCapture()

    setTimeout(() => ensureRFIDFocus(), 50)

    if (!scannedRfid) {
        console.error('No RFID data provided:', rfid)
        scheduleAutoFingerprintScan()
        return
    }

    const now = Date.now()
    if (now - lastScannedTime.value < SCAN_COOLDOWN_MS) {
        console.error('Scan cooldown active, ignoring scan')
        scheduleAutoFingerprintScan()
        return
    }
    lastScannedTime.value = now

    try {
        startProcessing('rfid', 'Processing RFID attendance...')
        setScannerStatus('idle')
        const recorded = await verifyEmployeeAndSubmit(scannedRfid, 'rfid')
        if (!recorded) {
            stopProcessing()
            scheduleAutoFingerprintScan()
        }
    } catch (e) {
        console.error('Error submitting RFID attendance:', e)
        setTemporaryScannerError('rfid_not_recognized')
        stopProcessing()
        scheduleAutoFingerprintScan()
    }
}

const submitManualAttendance = async () => {
    pauseAutoFingerprintScan()

    const password = employeePassword.value.trim()

    if (!password) {
        toast.add({
            severity: 'warn',
            summary: 'Warning',
            detail: 'Enter password first.',
            life: 5000,
        })
        return
    }

    await ensureAttendanceFlowReady()

    try {
        startProcessing('keypad', 'Processing keypad attendance...')
        const recorded = await verifyEmployeeAndSubmit(password, 'keypad')
        if (recorded) {
            employeePassword.value = ''
            hasTypedEmployeePassword.value = false
            setTimeout(() => forceRFIDFocus(), 50)
        } else {
            stopProcessing()
            scheduleAutoFingerprintScan()
        }
    } catch (e) {
        console.error('Error submitting keypad attendance:', e)
        stopProcessing()
        scheduleAutoFingerprintScan()
    }
}

const fingerprintAttendancePayload = (
    commandId: string,
    attendanceAction?: AttendanceAction | '',
) => ({
    command_id: commandId,
    attendance_type: attendanceAction || undefined,
    occurred_at: trustedNowIso(),
    offline_id: commandId,
    latitude: coords.value.latitude,
    longitude: coords.value.longitude,
    location: locationLabel(),
    location_source: locationSource.value || 'live',
})

const runFingerprintAttendance = async (
    automatic = false,
    scanVersion = autoFingerprintScanVersion,
): Promise<boolean> => {
    const attendanceAction = attendanceType.value

    if (!automatic) {
        await ensureAttendanceFlowReady(attendanceAction)
    }

    if (
        locationLoading.value ||
        locationError.value ||
        !isLocationReady.value
    ) {
        if (!automatic) {
            toast.add({
                severity: 'error',
                summary: 'Location',
                detail: locationError.value || 'Waiting for GPS location.',
                life: 5000,
            })
        }

        return false
    }

    try {
        if (!automatic) {
            startProcessing('fingerprint', 'Connecting to Fingerprint scanner...')
        } else {
            setScannerStatus('idle')
        }

        const commandId = createOfflineId()
        const payload = fingerprintAttendancePayload(commandId, attendanceAction)

        await startZktecoAttendanceScan(payload, {
            launchBridge: !automatic,
        })

        setScannerStatus('idle')
        await pollZktecoBridgeStatus(
            commandId,
            automatic ? AUTO_FINGERPRINT_SCAN_WINDOW_MS : 30000,
            automatic
                ? () => scanVersion === autoFingerprintScanVersion
                : undefined,
        )
        return true
    } catch (error) {
        if (!automatic) {
            setTemporaryScannerError('fingerprint_not_found')
            toast.add({
                severity: 'error',
                summary: 'Fingerprint',
                detail:
                    error instanceof Error
                        ? error.message
                        : 'Unable to start fingerprint attendance.',
                life: 5000,
            })
            resetAttendanceSelection(false)
        }

        return false
    } finally {
        if (!automatic) stopProcessing()
    }
}

const scheduleAutoFingerprintScan = (delay = AUTO_FINGERPRINT_RETRY_MS) => {
    clearAutoFingerprintScan()

    autoFingerprintTimeout = setTimeout(async () => {
        if (
            isAutoFingerprintScanActive ||
            isProcessing.value ||
            isCameraActive.value ||
            !isLocationReady.value
        ) {
            scheduleAutoFingerprintScan()
            return
        }

        isAutoFingerprintScanActive = true
        const scanVersion = ++autoFingerprintScanVersion

        try {
            await runFingerprintAttendance(true, scanVersion)
        } finally {
            if (scanVersion === autoFingerprintScanVersion) {
                isAutoFingerprintScanActive = false
                scheduleAutoFingerprintScan()
            }
        }
    }, delay)
}

const pollZktecoBridgeStatus = async (
    commandId: string,
    timeoutMs = 30000,
    shouldContinue: (() => boolean) | undefined = undefined,
): Promise<void> => {
    let lastMessage = ''
    let attendancePhotoSent = false

    closeZktecoEvents()

    await new Promise<void>((resolve, reject) => {
        const timeout = setTimeout(() => {
            void cancelZktecoCommand(commandId)
            closeZktecoEvents()
            reject(new Error('No fingerprint scan was received. Please try again.'))
        }, timeoutMs)

        const finish = () => {
            clearTimeout(timeout)
            closeZktecoEvents()
            resolve()
        }

        const fail = (error: Error) => {
            void cancelZktecoCommand(commandId)
            clearTimeout(timeout)
            closeZktecoEvents()
            reject(error)
        }

        const handleStatus = async (status: any) => {
            if (shouldContinue && !shouldContinue()) {
                finish()
                return
            }

            if (!status || (status.command_id && status.command_id !== commandId)) {
                return
            }

            if (status.message && status.message !== lastMessage) {
                lastMessage = status.message
            }

            if (status.state === 'waiting_for_scan') {
                if (
                    String(status.message ?? '')
                        .toLowerCase()
                        .includes('not recognized')
                ) {
                    setTemporaryScannerError('fingerprint_not_found')
                    void cancelZktecoCommand(commandId)
                    finish()
                    return
                }

                setScannerStatus('idle')
                return
            }

            if (status.state === 'matched') {
                setScannerStatus('fingerprint_matched')
                return
            }

            if (status.state === 'awaiting_browser_photo' && !attendancePhotoSent) {
                attendancePhotoSent = true
                setScannerStatus('fingerprint_matched')
                faceStatusText.value = 'Verifying face...'

                const employeeIdentifier =
                    status.employee_id || status.data?.employee?.employee_id

                if (!employeeIdentifier) {
                    fail(new Error('Fingerprint match did not include an employee.'))
                    return
                }

                const employee = await verifyEmployeeIdentifier(
                    employeeIdentifier,
                    'fingerprint',
                )

                if (!employee) {
                    fail(new Error('Fingerprint employee could not be verified.'))
                    return
                }

                await openCameraForCapture({ loadFaceVerification: true })

                const matchedFace = await verifyLiveFaceMatchesEmployee(employee)

                if (!matchedFace) {
                    resetAttendanceSelection()
                    fail(new Error('Face verification failed for fingerprint match.'))
                    return
                }

                faceStatusText.value = 'Recording fingerprint attendance...'

                const finalizePayload = await postZktecoBridgeCommand(
                    `${props.zktecoBridgeUrl.replace(/\/$/, '')}/commands/${encodeURIComponent(commandId)}/finalize-attendance`,
                    {
                        attendance_image: matchedFace.image,
                        latitude: coords.value.latitude,
                        longitude: coords.value.longitude,
                        location: locationLabel(),
                        location_source: locationSource.value || 'live',
                    },
                )

                await handleStatus({
                    ...finalizePayload,
                    command_id: commandId,
                    state: 'success',
                    message:
                        finalizePayload.message ||
                        'Attendance recorded successfully.',
                })
                return
            }

            if (status.state === 'success') {
                setScannerStatus('attendance_recorded')
                if (status.employee || status.data?.employee) {
                    emit('employeeVerified', status.employee || status.data.employee)
                }

                toast.add({
                    severity: 'success',
                    summary: 'Success',
                    detail:
                        status.message ?? 'Attendance recorded successfully.',
                    life: 5000,
                })
                announceAttendanceGreeting({
                    first_name:
                        status.employee_first_name ||
                        status.employee_name?.split(' ')?.[0] ||
                        '',
                    is_birthday: Boolean(status.is_birthday),
                    attendance_type: status.attendance_type || attendanceType.value,
                })
                resetAttendanceSelection(false)
                announceAttendanceRecorded({ payload: status })
                finish()
                return
            }

            if (status.state === 'error') {
                setTemporaryScannerError('fingerprint_not_found')
                fail(new Error(status.message || 'Fingerprint attendance failed.'))
            }
        }

        zktecoEvents = new EventSource(
            `${props.zktecoBridgeUrl.replace(/\/$/, '')}/events?command_id=${encodeURIComponent(commandId)}`,
        )
        zktecoEvents.onmessage = (event) => {
            handleStatus(JSON.parse(event.data || '{}')).catch(fail)
        }
        ;[
            'waiting_for_scan',
            'matched',
            'awaiting_browser_photo',
            'recording',
            'success',
            'error',
        ].forEach((state) => {
            zktecoEvents?.addEventListener(state, (event) => {
                handleStatus(JSON.parse(event.data || '{}')).catch(fail)
            })
        })
    })
}

const startZktecoAttendanceScan = async (
    payload: Record<string, unknown>,
    options: { launchBridge?: boolean } = {},
): Promise<void> => {
    const attendanceUrl = `${props.zktecoBridgeUrl.replace(/\/$/, '')}/commands/attendance`
    const shouldLaunchBridge = options.launchBridge ?? true

    try {
        await postZktecoBridgeCommand(attendanceUrl, payload)
        return
    } catch {
        if (!shouldLaunchBridge) {
            throw new Error('Fingerprint scanner bridge is not connected.')
        }

        const launchPayload = {
            command_id: payload.command_id,
            attendance_type: payload.attendance_type,
            occurred_at: payload.occurred_at,
            offline_id: payload.offline_id,
            latitude: payload.latitude,
            longitude: payload.longitude,
            location: payload.location,
            location_source: payload.location_source,
        }

        window.location.href = `zkteco-bridge://attendance?payload=${encodeURIComponent(JSON.stringify(launchPayload))}`
    }

    const startedAt = Date.now()
    let lastError: Error | null = null

    while (Date.now() - startedAt < 12000) {
        await new Promise((resolve) => setTimeout(resolve, 700))

        try {
            await postZktecoBridgeCommand(attendanceUrl, payload)
            return
        } catch (error) {
            lastError =
                error instanceof Error
                    ? error
                    : new Error('Unable to connect to Finger Scanner Bridge.')
        }
    }

    throw lastError || new Error('Unable to connect to Finger Scanner Bridge.')
}

const postZktecoBridgeCommand = async (
    url: string,
    payload: Record<string, unknown>,
): Promise<Record<string, any>> => {
    const controller = new AbortController()
    const timeout = setTimeout(() => controller.abort(), 45000)

    const response = await fetch(url, {
        method: 'POST',
        signal: controller.signal,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload),
    }).finally(() => clearTimeout(timeout))

    const bridgePayload = await response.json().catch(() => ({}))

    if (!response.ok) {
        throw new Error(
            bridgePayload.message || 'Unable to connect to Finger Scanner Bridge.',
        )
    }

    return bridgePayload
}

const cancelZktecoCommand = async (commandId: string): Promise<void> => {
    if (!commandId) return

    const bridgeUrl = props.zktecoBridgeUrl.endsWith('/')
        ? props.zktecoBridgeUrl.slice(0, -1)
        : props.zktecoBridgeUrl

    try {
        await postZktecoBridgeCommand(
            bridgeUrl + '/commands/' + encodeURIComponent(commandId) + '/cancel',
            {},
        )
    } catch {
        // The command may already be complete or the bridge may be unavailable.
    }
}

const createOfflineId = (): string =>
    crypto.randomUUID?.() ??
    `offline-${Date.now()}-${Math.random().toString(36).slice(2)}`

const submitAttendance = async (
    employeeIdentifier: string,
    method: AttendanceMethod,
    employeeName?: string,
    image?: string,
): Promise<void> => {
    const attendanceAction = attendanceType.value

    if (
        locationLoading.value ||
        locationError.value ||
        !isLocationReady.value
    ) {
        toast.add({
            severity: 'error',
            summary: 'Location',
            detail: locationError.value || 'Waiting for GPS location.',
            life: 5000,
        })

        throw new Error('Location is not ready.')
    }

    startProcessing(method, 'Recording attendance...')

    const result = await syncStore.submitOrQueueAttendance({
        offlineId: createOfflineId(),
        occurredAt: trustedNowIso(),
        employeeIdentifier,
        employeeName: employeeName || employeeIdentifier,
        attendanceMethod: method,
        attendanceType: attendanceAction || undefined,
        latitude: coords.value.latitude,
        longitude: coords.value.longitude,
        location: locationLabel(),
        locationSource: locationSource.value || 'live',
        imageBlob: image ? base64ToBlob(image, 'image/jpeg') : undefined,
        imageFileName: image ? `attendance_${Date.now()}.jpg` : undefined,
    })

    if (result.queued) {
        toast.add({
            severity: 'info',
            summary: 'Saved Offline',
            detail: result.message,
            life: 10000,
        })
        setScannerStatus('attendance_recorded')
        resetAttendanceSelection(false)
        return
    }

    toast.add({
        severity: 'success',
        summary: 'Success',
        detail: result.payload?.message ?? 'Attendance recorded successfully.',
        life: 5000,
    })

    announceAttendanceGreeting(result.payload?.greeting)
    setScannerStatus('attendance_recorded')
    resetAttendanceSelection(false)
    announceAttendanceRecorded(result)
}
const base64ToBlob = (base64: string, mimeType: string): Blob => {
    const byteString = atob(base64.split(',')[1])
    const buffer = new Uint8Array(byteString.length)

    for (let i = 0; i < byteString.length; i++) {
        buffer[i] = byteString.charCodeAt(i)
    }

    return new Blob([buffer], { type: mimeType })
}

watch(showEmployeeIdInputField, async (val) => {
    if (val) {
        await nextTick()
        forceRFIDFocus()
    }
})

const onDocumentClick = (e: MouseEvent) => {
    if (e.target === empIdInput.value) return
    focusRFID()
}

onMounted(async () => {
    loadClockOffset()
    updateTime()
    interval = setInterval(updateTime, 1000)
    syncClockQuietly()
    clockSyncInterval = setInterval(syncClockQuietly, CLOCK_SYNC_INTERVAL_MS)
    getLocation().catch(() => null)

    ensureRFIDFocus()

    focusInterval = setInterval(() => {
        ensureRFIDFocus()
    }, 1000)

    document.addEventListener('click', onDocumentClick)
    document.addEventListener('touchend', onDocumentClick)
    window.addEventListener('online', syncClockQuietly)

    scheduleAutoFingerprintScan(1000)
})

onUnmounted(() => {
    if (stream) {
        stream.getTracks().forEach((track) => track.stop())
    }

    clearInterval(interval)
    clearInterval(clockSyncInterval)
    clearInterval(focusInterval)
    if (scannerStatusResetTimeout) clearTimeout(scannerStatusResetTimeout)
    clearAutoFingerprintScan()
    closeZktecoEvents()
    stopCamera()

    document.removeEventListener('click', onDocumentClick)
    document.removeEventListener('touchend', onDocumentClick)
    window.removeEventListener('online', syncClockQuietly)
    if (rfidTimeout) clearTimeout(rfidTimeout)
})
</script>

<template>
    <div
        v-if="isCameraActive"
        class="relative flex flex-col overflow-hidden rounded-2xl border border-black/5 bg-white p-4 shadow-2xl shadow-black/10"
        :class="{
            'fixed -left-[9999px] top-0 h-px w-px opacity-0 pointer-events-none':
                isSilentCameraCapture,
        }"
        :aria-hidden="isSilentCameraCapture"
    >
        <div
            class="absolute left-8 top-8 z-10 flex items-center gap-2 rounded-full bg-brand-bg px-4 py-2 shadow-lg"
        >
            <div class="h-2 w-2 animate-pulse rounded-full bg-white" />
            <span class="text-xs font-black text-white">
                LIVE
            </span>
        </div>

        <div
            class="absolute right-8 top-8 z-10 flex items-center gap-2 rounded-full border border-black/10 bg-white px-4 py-2 shadow-lg"
        >
            <LoaderCircle
                v-if="locationLoading"
                class="h-4 w-4 animate-spin text-brand-stroke"
            />
            <TriangleAlert
                v-else-if="locationError"
                class="h-4 w-4 text-red-600"
            />
            <TriangleAlert
                v-else-if="accuracyWarning || usingCachedLocation"
                class="h-4 w-4 text-yellow-600"
            />
            <MapPin v-else class="h-4 w-4 text-green-600" />
            <span class="text-xs font-bold text-brand-stroke">
                <template v-if="locationLoading">GPS...</template>
                <template v-else-if="locationError">GPS blocked</template>
                <template v-else-if="usingCachedLocation">Cached GPS</template>
                <template v-else-if="accuracyWarning">Low GPS</template>
                <template v-else-if="isLocationReady">GPS ready</template>
                <template v-else>GPS pending</template>
            </span>
        </div>

        <div
            class="relative aspect-video w-full overflow-hidden rounded-[1.5rem] bg-brand-stroke"
        >
            <video
                ref="videoRef"
                autoplay
                playsinline
                muted
                class="home-camera-video h-full w-full object-cover"
                :class="{ loaded: isVideoReady }"
            />

            <canvas
                ref="overlayRef"
                class="absolute inset-0 h-full w-full pointer-events-none"
            />
            <canvas ref="canvasRef" style="display: none" />

            <div
                v-if="isError"
                class="absolute inset-0 flex items-center justify-center bg-brand-stroke/90 px-6 text-center text-white"
            >
                <p class="text-sm font-semibold">
                    Camera blocked. Check browser permissions to proceed.
                </p>
            </div>
        </div>

        <p
            v-if="showCamera"
            class="mt-5 text-center text-sm font-bold text-black/60"
            :class="{
                'text-red-700': cameraGuidanceIsWarning,
            }"
        >
            {{ cameraGuidanceText }}
        </p>
    </div>

    <div class="flex flex-col gap-4">
        <div
            class="shrink-0 rounded-2xl border border-black/5 bg-white p-4 shadow-xl shadow-black/10 animate-fade-up md:p-5"
        >
            <div
                v-if="!showFaceCheckOnly"
                class="mb-5 flex flex-col items-center space-y-0 text-center"
            >
                <p
                    class="text-s font-bold text-brand-bg"
                >
                    {{ currentDate }}
                </p>
                <h1
                    class="font-mona-sans text-3xl font-black text-brand-stroke tabular-nums lg:text-4xl"
                >
                    {{ currentTime }}
                </h1>
            </div>

            <div
                v-if="showFaceCheckOnly"
                data-face-auth-status="checking-employee"
                class="flex min-h-40 w-full items-center justify-center rounded-xl border border-brand-bg/15 bg-brand-paragraph px-4 py-7 text-center"
            >
                <p class="text-sm font-black leading-snug text-brand-stroke md:text-base">
                    {{ faceStatusText }}
                </p>
            </div>

            <div v-else class="w-full">
                <div class="grid grid-cols-2 gap-4">
                    <button
                        @click="handleTimeAction('time-in')"
                        :disabled="isProcessing"
                        class="group relative flex items-center justify-center gap-2 rounded-xl bg-brand-bg px-3 py-3 font-black text-white shadow-lg shadow-red-950/15 transition hover:bg-brand-tertiary focus:outline-none focus:ring-4 focus:ring-brand-bg/20"
                        :class="{
                            'ring-4 ring-brand-bg/25 ring-offset-2 ring-offset-white':
                                attendanceType === 'time-in',
                            'cursor-not-allowed opacity-60':
                                isProcessing,
                        }"
                        :aria-pressed="attendanceType === 'time-in'"
                    >
                        <LogIn class="h-4 w-4" />
                        <span class="text-sm">Time In</span>
                        <span
                            v-if="attendanceType === 'time-in'"
                            class="absolute right-2 top-2 h-2.5 w-2.5 rounded-full bg-white"
                            aria-hidden="true"
                        />
                    </button>

                    <button
                        @click="handleTimeAction('time-out')"
                        :disabled="isProcessing"
                        class="group relative flex items-center justify-center gap-2 rounded-xl border border-brand-bg/15 bg-brand-paragraph px-3 py-3 font-black text-brand-stroke shadow-sm transition hover:border-brand-bg/30 hover:bg-white focus:outline-none focus:ring-4 focus:ring-brand-bg/15"
                        :class="{
                            'ring-4 ring-brand-bg/25 ring-offset-2 ring-offset-white':
                                attendanceType === 'time-out',
                            'cursor-not-allowed opacity-60':
                                isProcessing,
                        }"
                        :aria-pressed="attendanceType === 'time-out'"
                    >
                        <LogOut class="h-4 w-4" />
                        <span class="text-sm">Time Out</span>
                        <span
                            v-if="attendanceType === 'time-out'"
                            class="absolute right-2 top-2 h-2.5 w-2.5 rounded-full bg-brand-bg"
                            aria-hidden="true"
                        />
                    </button>
                </div>

                <button
                    v-if="props.attendanceSchedule.show_face_attendance_button"
                    type="button"
                    @click="submitFaceAttendance"
                    :disabled="isProcessing"
                    class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl border border-brand-bg/15 bg-white px-4 py-2.5 text-sm font-black text-brand-stroke shadow-sm transition hover:border-brand-bg/30 hover:bg-brand-paragraph disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <Camera class="h-5 w-5" />
                    Facial Recognition
                </button>

                <div
                    data-scanner-status-version="20260715-always-on"
                    class="mt-3 rounded-xl border border-brand-bg/15 bg-brand-paragraph px-4 py-2.5 text-center"
                    :class="{
                        'border-red-200 bg-red-50':
                            scannerStatusTone === 'error',
                    }"
                >
                    <p
                        class="text-xs font-black text-brand-stroke"
                        :class="{
                            'text-red-800': scannerStatusTone === 'error',
                        }"
                    >
                        {{ scannerStatusText }}
                    </p>
                </div>

                <div
                    v-if="isProcessing"
                    class="mt-3 flex items-center gap-3 rounded-xl border border-brand-bg/15 bg-brand-paragraph px-4 py-2.5 text-left"
                >
                    <LoaderCircle class="h-5 w-5 shrink-0 animate-spin text-brand-stroke" />
                    <p
                        class="text-xs font-black text-brand-stroke"
                    >
                        {{ processingLabel }}
                    </p>
                </div>

                <div class="flex flex-col justify-center gap-3" v-else>
                    <div class="w-full">
                        <input
                            ref="rfidInput"
                            type="text"
                            autocomplete="off"
                            class="absolute -top-96"
                            style="opacity: 0; pointer-events: none"
                            @input="onRFIDInput"
                            @keydown="onRFIDKeydown"
                        />

                        <div class="flex items-center justify-center">
                            <div
                                v-if="showEmployeeIdInputField"
                                class="mx-auto mt-3 w-full max-w-80"
                            >
                                <input
                                    ref="empIdInput"
                                    v-model="employeePassword"
                                    type="text"
                                    name="keypad-attendance-pin"
                                    autocomplete="off"
                                    inputmode="numeric"
                                    pattern="[0-9]*"
                                    autocapitalize="off"
                                    spellcheck="false"
                                    style="-webkit-text-security: disc"
                                    class="mb-2 h-12 w-full rounded-lg border border-brand-bg/20 bg-white px-3 text-center text-3xl font-black text-brand-stroke shadow-inner"
                                    @focus="onEmpIdFocus"
                                    @input="onEmpIdInput"
                                    @keydown="onEmpIdKeydown"
                                />

                                <div class="grid grid-cols-3 gap-2">
                                    <button
                                        v-for="digit in keypadDigits.slice(0, 9)"
                                        :key="digit"
                                        type="button"
                                        class="flex h-12 items-center justify-center rounded-lg border border-brand-bg/15 bg-white text-lg font-black text-brand-stroke shadow-sm active:scale-95"
                                        @click="appendKeypadDigit(digit)"
                                    >
                                        {{ digit }}
                                    </button>
                                    <button
                                        type="button"
                                        class="flex h-12 items-center justify-center rounded-lg bg-brand-tertiary text-white shadow-sm active:scale-95"
                                        aria-label="Clear"
                                        @click="clearKeypad"
                                    >
                                        <Eraser class="h-5 w-5" />
                                    </button>
                                    <button
                                        type="button"
                                        class="flex h-12 items-center justify-center rounded-lg border border-brand-bg/15 bg-white text-lg font-black text-brand-stroke shadow-sm active:scale-95"
                                        @click="appendKeypadDigit('0')"
                                    >
                                        0
                                    </button>
                                    <button
                                        type="button"
                                        class="flex h-12 items-center justify-center rounded-lg border border-brand-bg/15 bg-white text-brand-stroke shadow-sm active:scale-95"
                                        aria-label="Delete"
                                        @click="deleteKeypadDigit"
                                    >
                                        <Delete class="h-5 w-5" />
                                    </button>
                                </div>

                                <button
                                    type="button"
                                    class="mt-2 flex h-11 w-full items-center justify-center rounded-lg bg-brand-bg text-sm font-black text-white shadow-md shadow-red-950/10 active:scale-[0.98]"
                                    @click="submitManualAttendance"
                                >
                                    Enter
                                </button>
                            </div>
                        </div>
                    </div>

                    <p
                        v-if="
                            !showEmployeeIdInputField &&
                            faceStatusText !== 'Face verification ready.'
                        "
                        class="text-xs font-black text-brand-bg"
                    >
                        {{ faceStatusText }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped></style>
