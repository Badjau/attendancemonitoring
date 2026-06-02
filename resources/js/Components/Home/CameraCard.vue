<script setup lang="ts">
import * as faceapi from 'face-api.js'
import axios from 'axios'
import {
    Fingerprint,
    LoaderCircle,
    LogIn,
    LogOut,
    MapPin,
    ScanFace,
    TriangleAlert,
} from '@lucide/vue'
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useToast } from 'primevue'
import { useGeolocator } from '@/Composables/useGeolocator.js'
import { useSyncStore } from '@/Stores/sync.js'
import { mapFaceBoxToObjectCover } from '@/Utils/faceOverlay.js'

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
    profile_url?: string | null
}
type LiveFaceMatch = {
    employee: VerifiedEmployee
    detection: faceapi.WithFaceDescriptor<
        faceapi.WithFaceLandmarks<{
            detection: faceapi.FaceDetection
        }>
    >
    detectedFaceCount: number
}
type AttendanceSchedule = {
    time_in_start: string
    time_in_end: string
    time_out_start: string
    time_out_end: string
}

const props = defineProps<{
    employees: VerifiedEmployee[]
    attendanceSchedule: AttendanceSchedule
    zktecoBridgeUrl: string
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
const isFaceModelReady = ref(false)
const faceStatusText = ref('Face verification ready.')

const currentTime = ref('')
const currentDate = ref('')

const showEmployeeIdInputField = ref(false)

const rfidInput = ref<HTMLInputElement | null>(null)
const empIdInput = ref<HTMLInputElement | null>(null)
const rfidBuffer = ref('')
const employeePassword = ref('')
const hasTypedEmployeePassword = ref(false)

let stream: MediaStream | null = null
let interval: ReturnType<typeof setInterval>
let focusInterval: ReturnType<typeof setInterval>
let faceDetectionInterval: ReturnType<typeof setInterval> | null = null
let autoFingerprintTimeout: ReturnType<typeof setTimeout> | null = null
let rfidTimeout: any = null
let isDrawingFaceDetectorOverlay = false
let isAutoFingerprintScanActive = false
let autoFingerprintScanVersion = 0
const registeredFaceDescriptors = new Map<string, Float32Array>()
const registeredFaceDescriptorPromises = new Map<
    string,
    Promise<Float32Array | null>
>()

const lastScannedTime = ref(0)
const SCAN_COOLDOWN_MS = 1000
const AUTO_FINGERPRINT_RETRY_MS = 2500
const AUTO_FINGERPRINT_SCAN_WINDOW_MS = 120000
const FACE_MODEL_PATH = '/models/face-api'
const FACE_MATCH_THRESHOLD = 0.52
const ATTENDANCE_IMAGE_MAX_WIDTH = 960
const faceDetectorOptions = new faceapi.TinyFaceDetectorOptions({
    inputSize: 320,
    scoreThreshold: 0.5,
})
const isLocationReady = computed(
    () =>
        Boolean(coords.value) &&
        Number.isFinite(coords.value.latitude) &&
        Number.isFinite(coords.value.longitude) &&
        !locationError.value,
)
const showCamera = computed(() => isCameraActive.value)
const isProcessing = computed(() => Boolean(processingMethod.value))
const processingLabel = computed(() =>
    faceStatusText.value && faceStatusText.value !== 'Face verification ready.'
        ? faceStatusText.value
        : 'Processing, please wait...',
)

const employeeFullName = (employee: VerifiedEmployee): string =>
    `${employee.first_name} ${employee.last_name}`.trim()

const locationLabel = (): string => {
    const currentCoords = coords.value as any

    return address.value || currentCoords.address || ''
}

const loadFaceModels = async () => {
    if (isFaceModelReady.value) return

    faceStatusText.value = 'Loading face verification...'

    await Promise.all([
        faceapi.nets.tinyFaceDetector.loadFromUri(FACE_MODEL_PATH),
        faceapi.nets.faceLandmark68Net.loadFromUri(FACE_MODEL_PATH),
        faceapi.nets.faceRecognitionNet.loadFromUri(FACE_MODEL_PATH),
    ])

    isFaceModelReady.value = true
    faceStatusText.value = 'Face verification ready.'
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

const stopCamera = (): any => {
    stream?.getTracks().forEach((track) => track.stop())
    stream = null
    if (videoRef.value) videoRef.value.srcObject = null
    clearFaceDetectorOverlay()
    isVideoReady.value = false
    isCameraActive.value = false
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

const clearAutoFingerprintScan = () => {
    if (autoFingerprintTimeout) {
        clearTimeout(autoFingerprintTimeout)
        autoFingerprintTimeout = null
    }
}

const pauseAutoFingerprintScan = () => {
    autoFingerprintScanVersion++
    clearAutoFingerprintScan()
    isAutoFingerprintScanActive = false
}

const drawFaceDetectorOverlay = async () => {
    if (isDrawingFaceDetectorOverlay) return
    if (
        !videoRef.value ||
        !overlayRef.value ||
        !isVideoReady.value ||
        !isFaceModelReady.value
    )
        return
    if (videoRef.value.paused || videoRef.value.ended) return

    isDrawingFaceDetectorOverlay = true

    try {
        const video = videoRef.value
        const canvas = overlayRef.value
        const displaySize = {
            width: video.clientWidth,
            height: video.clientHeight,
        }

        if (!displaySize.width || !displaySize.height) return

        faceapi.matchDimensions(canvas, displaySize)

        const detections = await faceapi.detectAllFaces(
            video,
            faceDetectorOptions,
        )

        const context = canvas.getContext('2d')
        context?.clearRect(0, 0, canvas.width, canvas.height)

        detections.forEach((detection, index) => {
            const box = mapFaceBoxToObjectCover(detection.box, video)
            if (!box) return

            const drawBox = new faceapi.draw.DrawBox(box, {
                label:
                    detections.length === 1
                        ? 'Face detected'
                        : `Face ${index + 1}`,
                boxColor: '#f9bc60',
                lineWidth: 3,
            })

            drawBox.draw(canvas)
        })
    } finally {
        isDrawingFaceDetectorOverlay = false
    }
}

const startFaceDetectorOverlay = () => {
    clearFaceDetectorOverlay()

    faceDetectionInterval = setInterval(() => {
        drawFaceDetectorOverlay().catch((error) => {
            console.error('Face detector overlay failed:', error)
            clearFaceDetectorOverlay()
        })
    }, 1200)
}

const captureImage = (): string | null => {
    if (!videoRef.value || !canvasRef.value || !isVideoReady.value) return null

    const video = videoRef.value
    const canvas = canvasRef.value

    const scale = Math.min(1, ATTENDANCE_IMAGE_MAX_WIDTH / video.videoWidth)
    canvas.width = Math.round(video.videoWidth * scale)
    canvas.height = Math.round(video.videoHeight * scale)

    const ctx = canvas.getContext('2d')
    if (!ctx) return null

    ctx.drawImage(video, 0, 0, canvas.width, canvas.height)

    return canvas.toDataURL('image/jpeg', 0.72)
}

const cropFaceFromVideo = (
    detection: faceapi.FaceDetection,
    paddingRatio = 0.25,
): string | null => {
    if (!videoRef.value || !canvasRef.value || !isVideoReady.value) return null

    const video = videoRef.value
    const canvas = canvasRef.value
    const { x, y, width, height } = detection.box
    const paddingX = width * paddingRatio
    const paddingY = height * paddingRatio

    const sourceX = Math.max(0, x - paddingX)
    const sourceY = Math.max(0, y - paddingY)
    const sourceWidth = Math.min(
        video.videoWidth - sourceX,
        width + paddingX * 2,
    )
    const sourceHeight = Math.min(
        video.videoHeight - sourceY,
        height + paddingY * 2,
    )

    if (sourceWidth <= 0 || sourceHeight <= 0) return null

    canvas.width = Math.round(sourceWidth)
    canvas.height = Math.round(sourceHeight)

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

const updateTime = () => {
    const now = new Date()

    currentTime.value = now.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
    })

    currentDate.value = now.toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    })
}

const inferredAttendanceType = (): AttendanceAction => {
    const now = new Date()
    const minutesFromMidnight = now.getHours() * 60 + now.getMinutes()

    if (
        isMinuteWithinRange(
            minutesFromMidnight,
            timeToMinutes(props.attendanceSchedule.time_in_start),
            timeToMinutes(props.attendanceSchedule.time_in_end),
        )
    ) {
        return 'time-in'
    }

    if (
        isMinuteWithinRange(
            minutesFromMidnight,
            timeToMinutes(props.attendanceSchedule.time_out_start),
            timeToMinutes(props.attendanceSchedule.time_out_end),
        )
    ) {
        return 'time-out'
    }

    return minutesFromMidnight <=
        timeToMinutes(props.attendanceSchedule.time_in_end)
        ? 'time-in'
        : 'time-out'
}

const timeToMinutes = (time: string): number => {
    const [hours = '0', minutes = '0'] = time.split(':')

    return Number(hours) * 60 + Number(minutes)
}

const isMinuteWithinRange = (
    minute: number,
    start: number,
    end: number,
): boolean => {
    if (start <= end) {
        return minute >= start && minute <= end
    }

    return minute >= start || minute <= end
}

const ensureAttendanceFlowReady = async (actionName?: AttendanceAction) => {
    if (actionName) {
        attendanceType.value = actionName
    }

    showEmployeeIdInputField.value = true
    await nextTick()
    forceRFIDFocus()
}

const openCameraForCapture = async () => {
    showEmployeeIdInputField.value = true
    isCameraActive.value = true
    await nextTick()
    await initializeCamera()
    await loadFaceModels().catch((error) => {
        console.error('Face model load failed:', error)
        faceStatusText.value = 'Face verification failed to load.'
    })
    if (isFaceModelReady.value) startFaceDetectorOverlay()
}

const handleTimeAction = async (actionName: AttendanceAction) => {
    await ensureAttendanceFlowReady(actionName)
    employeePassword.value = ''
    hasTypedEmployeePassword.value = false
}

const resetAttendanceSelection = () => {
    attendanceType.value = ''
    showEmployeeIdInputField.value = false
    employeePassword.value = ''
    hasTypedEmployeePassword.value = false
    isLoading.value = false
    processingMethod.value = ''
    faceStatusText.value = 'Face verification ready.'
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

const onRFIDInput = () => {
    const data = rfidInput.value?.value.trim()

    if (data && data.length > 0) {
        rfidBuffer.value = data

        if (rfidTimeout) clearTimeout(rfidTimeout)

        rfidTimeout = setTimeout(() => {
            submitRFIDAttendance(rfidBuffer.value)
        }, 100)
    }
}

const onRFIDKeydown = (e: KeyboardEvent) => {
    if (e.key === 'Enter') {
        e.preventDefault()

        if (rfidTimeout) clearTimeout(rfidTimeout)

        submitRFIDAttendance(rfidBuffer.value || rfidInput.value?.value)
    }
}

const onEmpIdFocus = (e: FocusEvent) => {
    const el = e.target as HTMLInputElement | null
    el?.select?.()
}

const onEmpIdInput = () => {
    hasTypedEmployeePassword.value = true
}

const onEmpIdKeydown = (e: KeyboardEvent) => {
    if (e.key !== 'Enter') return
    e.preventDefault()

    submitManualAttendance()
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

const captureAttendanceImage = (
    matchedFace?: LiveFaceMatch | null,
): string | null => {
    const shouldCropMatchedEmployeeFace = Boolean(
        matchedFace && matchedFace.detectedFaceCount > 1,
    )
    const image = shouldCropMatchedEmployeeFace
        ? cropFaceFromVideo(matchedFace!.detection.detection)
        : captureImage()

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

const verifyEmployeeIdentifier = async (
    employeeIdentifier: string,
    method: AttendanceMethod,
): Promise<VerifiedEmployee | null> => {
    try {
        faceStatusText.value = 'Checking employee...'

        const response = await axios.post('/attendance/verify-employee', {
            employee_id: employeeIdentifier,
            attendance_method: method,
        })

        return response.data.employee as VerifiedEmployee
    } catch (error: any) {
        const message =
            error?.response?.data?.message ??
            Object.values(error?.response?.data?.errors ?? {})?.[0]?.[0] ??
            'Employee is not existing.'

        toast.add({
            severity: 'error',
            summary: 'Employee',
            detail: message,
            life: 5000,
        })

        faceStatusText.value = message
        resetAttendanceSelection()

        return null
    }
}

const getRegisteredFaceDescriptor = async (
    employee: VerifiedEmployee,
): Promise<Float32Array | null> => {
    const cachedDescriptor = registeredFaceDescriptors.get(employee.employee_id)
    if (cachedDescriptor) return cachedDescriptor

    const cachedPromise = registeredFaceDescriptorPromises.get(
        employee.employee_id,
    )
    if (cachedPromise) return cachedPromise

    if (!employee.profile_url) return null

    const descriptorPromise = (async () => {
        const image = await faceapi.fetchImage(employee.profile_url!)
        const detection = await faceapi
            .detectSingleFace(image, faceDetectorOptions)
            .withFaceLandmarks()
            .withFaceDescriptor()

        if (!detection) return null

        registeredFaceDescriptors.set(employee.employee_id, detection.descriptor)

        return detection.descriptor
    })()

    registeredFaceDescriptorPromises.set(
        employee.employee_id,
        descriptorPromise,
    )

    return descriptorPromise
}

const verifyLiveFaceMatchesEmployee = async (
    employee: VerifiedEmployee,
): Promise<LiveFaceMatch | null> => {
    if (!videoRef.value || !isVideoReady.value) {
        toast.add({
            severity: 'error',
            summary: 'Camera',
            detail: 'Camera not ready. Please allow camera access.',
            life: 5000,
        })
        return null
    }

    try {
        await loadFaceModels()

        faceStatusText.value = `Checking face for ${employeeFullName(employee)}...`

        const registeredDescriptor = await getRegisteredFaceDescriptor(employee)
        if (!registeredDescriptor) {
            toast.add({
                severity: 'error',
                summary: 'Face Verification',
                detail: 'The registered face photo cannot be read. Please register this face again.',
                life: 6000,
            })
            faceStatusText.value = 'Registered face cannot be read.'
            return null
        }

        const detections = await faceapi
            .detectAllFaces(videoRef.value, faceDetectorOptions)
            .withFaceLandmarks()
            .withFaceDescriptors()

        if (!detections.length) {
            const detail = 'No face detected. Look straight at the camera.'
            toast.add({
                severity: 'warn',
                summary: 'Face Verification',
                detail,
                life: 5000,
            })
            faceStatusText.value = detail
            return null
        }

        const bestDetection = detections.reduce(
            (best, detection) => {
                const distance = faceapi.euclideanDistance(
                    registeredDescriptor,
                    detection.descriptor,
                )

                return !best || distance < best.distance
                    ? { detection, distance }
                    : best
            },
            null as {
                detection: (typeof detections)[number]
                distance: number
            } | null,
        )

        const distance = bestDetection?.distance ?? Number.POSITIVE_INFINITY
        const isMatch = Boolean(
            bestDetection && distance <= FACE_MATCH_THRESHOLD,
        )

        if (!isMatch) {
            toast.add({
                severity: 'error',
                summary: 'Face Verification',
                detail: `Face does not match ${employeeFullName(employee)}.`,
                life: 6000,
            })
            faceStatusText.value = `Face mismatch. Distance: ${distance.toFixed(2)}.`
            return null
        }

        faceStatusText.value = `Face matched ${employeeFullName(employee)}.`
        return {
            employee,
            detection: bestDetection!.detection,
            detectedFaceCount: detections.length,
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

const recognizeLiveFaceEmployee = async (): Promise<LiveFaceMatch | null> => {
    if (!videoRef.value || !isVideoReady.value) {
        toast.add({
            severity: 'error',
            summary: 'Camera',
            detail: 'Camera not ready. Please allow camera access.',
            life: 5000,
        })
        return null
    }

    if (!props.employees.length) {
        toast.add({
            severity: 'warn',
            summary: 'Face Recognition',
            detail: 'No registered employee faces are available.',
            life: 5000,
        })
        faceStatusText.value = 'No registered faces available.'
        return null
    }

    await loadFaceModels()
    faceStatusText.value = 'Recognizing face...'

    const detections = await faceapi
        .detectAllFaces(videoRef.value, faceDetectorOptions)
        .withFaceLandmarks()
        .withFaceDescriptors()

    if (!detections.length) {
        toast.add({
            severity: 'warn',
            summary: 'Face Recognition',
            detail: 'No face detected. Look straight at the camera.',
            life: 5000,
        })
        faceStatusText.value = 'No face detected.'
        return null
    }

    let bestMatch: {
        employee: VerifiedEmployee
        detection: (typeof detections)[number]
        distance: number
    } | null = null

    const employeeDescriptors = await Promise.all(
        props.employees.map(async (employee) => ({
            employee,
            descriptor: await getRegisteredFaceDescriptor(employee),
        })),
    )

    for (const { employee, descriptor: registeredDescriptor } of employeeDescriptors) {
        if (!registeredDescriptor) continue

        for (const detection of detections) {
            const distance = faceapi.euclideanDistance(
                registeredDescriptor,
                detection.descriptor,
            )

            if (!bestMatch || distance < bestMatch.distance) {
                bestMatch = { employee, detection, distance }
            }
        }
    }

    if (!bestMatch || bestMatch.distance > FACE_MATCH_THRESHOLD) {
        toast.add({
            severity: 'error',
            summary: 'Face Recognition',
            detail: 'Face not recognized.',
            life: 5000,
        })
        faceStatusText.value = bestMatch
            ? `Face not recognized. Distance: ${bestMatch.distance.toFixed(2)}.`
            : 'Face not recognized.'
        return null
    }

    faceStatusText.value = `Recognized ${employeeFullName(bestMatch.employee)}.`

    return {
        employee: bestMatch.employee,
        detection: bestMatch.detection,
        detectedFaceCount: detections.length,
    }
}

const verifyEmployeeFaceAndSubmit = async (
    employeeIdentifier: string,
    method: AttendanceMethod,
): Promise<void> => {
    const employee = await verifyEmployeeIdentifier(employeeIdentifier, method)
    if (!employee) return

    await openCameraForCapture()

    const matchedFace = await verifyLiveFaceMatchesEmployee(employee)
    if (!matchedFace) {
        isLoading.value = false
        setTimeout(() => forceRFIDFocus(), 50)
        return
    }

    const image = captureAttendanceImage(matchedFace)
    if (!image) {
        isLoading.value = false
        return
    }

    await submitAttendance(
        employee.employee_id,
        image,
        method,
        employeeFullName(employee),
    )
}

const submitRFIDAttendance = async (rfid: any) => {
    pauseAutoFingerprintScan()

    const scannedRfid = rfid?.trim()

    rfidBuffer.value = ''
    if (rfidInput.value) rfidInput.value.value = ''

    setTimeout(() => ensureRFIDFocus(), 50)

    if (!scannedRfid) {
        console.error('No RFID data provided:', rfid)
        scheduleAutoFingerprintScan()
        return
    }

    await ensureAttendanceFlowReady()

    const now = Date.now()
    if (now - lastScannedTime.value < SCAN_COOLDOWN_MS) {
        console.error('Scan cooldown active, ignoring scan')
        scheduleAutoFingerprintScan()
        return
    }
    lastScannedTime.value = now

    try {
        startProcessing('rfid', 'Processing RFID attendance...')
        await verifyEmployeeFaceAndSubmit(scannedRfid, 'rfid')
    } catch (e) {
        console.error('Error submitting RFID attendance:', e)
        stopProcessing()
        scheduleAutoFingerprintScan()
    }
}

const submitManualAttendance = async () => {
    pauseAutoFingerprintScan()

    const password = employeePassword.value.trim()

    if (!hasTypedEmployeePassword.value) {
        employeePassword.value = ''
        return
    }

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
        await verifyEmployeeFaceAndSubmit(password, 'keypad')
        employeePassword.value = ''
        hasTypedEmployeePassword.value = false
        setTimeout(() => forceRFIDFocus(), 50)
    } catch (e) {
        console.error('Error submitting keypad attendance:', e)
        stopProcessing()
        scheduleAutoFingerprintScan()
    }
}

const submitFaceAttendance = async () => {
    pauseAutoFingerprintScan()

    const attendanceAction = attendanceType.value || undefined

    await ensureAttendanceFlowReady(attendanceAction)
    await openCameraForCapture()

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
        return
    }

    try {
        startProcessing('face', 'Processing facial recognition...')

        const matchedFace = await recognizeLiveFaceEmployee()
        if (!matchedFace) {
            stopProcessing()
            return
        }

        const image = captureAttendanceImage(matchedFace)
        if (!image) {
            stopProcessing()
            return
        }

        await submitAttendance(
            matchedFace.employee.employee_id,
            image,
            'face',
            employeeFullName(matchedFace.employee),
        )
    } catch (error) {
        console.error('Error submitting face attendance:', error)
        toast.add({
            severity: 'error',
            summary: 'Face Recognition',
            detail:
                error instanceof Error
                    ? error.message
                    : 'Facial recognition attendance failed.',
            life: 5000,
        })
        resetAttendanceSelection()
    }
}

const fingerprintAttendancePayload = (
    commandId: string,
    attendanceAction: AttendanceAction,
) => ({
    command_id: commandId,
    attendance_type: attendanceAction,
    occurred_at: new Date().toISOString(),
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
    const attendanceAction = attendanceType.value || inferredAttendanceType()

    if (automatic) {
        attendanceType.value = attendanceAction
    } else {
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
            faceStatusText.value = 'Fingerprint scanner ready.'
        }

        const commandId = createOfflineId()
        const payload = fingerprintAttendancePayload(commandId, attendanceAction)

        await startZktecoAttendanceScan(payload, {
            launchBridge: !automatic,
        })

        if (!automatic) {
            toast.add({
                severity: 'info',
                summary: 'Fingerprint',
                detail: 'Scan your registered finger on the scanner.',
                life: 8000,
            })
        }

        faceStatusText.value = 'Scan your registered finger on the scanner.'
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
            toast.add({
                severity: 'error',
                summary: 'Fingerprint',
                detail:
                    error instanceof Error
                        ? error.message
                        : 'Unable to start fingerprint attendance.',
                life: 5000,
            })
            resetAttendanceSelection()
        }

        return false
    } finally {
        if (!automatic) stopProcessing()
    }
}

const submitFingerprintAttendance = async () => {
    clearAutoFingerprintScan()
    await runFingerprintAttendance(false)
    scheduleAutoFingerprintScan()
}

const scheduleAutoFingerprintScan = (delay = AUTO_FINGERPRINT_RETRY_MS) => {
    clearAutoFingerprintScan()

    autoFingerprintTimeout = setTimeout(async () => {
        if (
            isAutoFingerprintScanActive ||
            isProcessing.value ||
            showEmployeeIdInputField.value ||
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
    const statusUrl = `${props.zktecoBridgeUrl.replace(/\/$/, '')}/status`
    const startedAt = Date.now()
    let lastMessage = ''
    let attendancePhotoSent = false

    while (Date.now() - startedAt < timeoutMs) {
        if (shouldContinue && !shouldContinue()) return

        await new Promise((resolve) => setTimeout(resolve, 1000))

        if (shouldContinue && !shouldContinue()) return

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

        if (status.message && status.message !== lastMessage) {
            lastMessage = status.message
            faceStatusText.value = status.message
        }

        if (status.state === 'matched' && !attendancePhotoSent) {
            attendancePhotoSent = true
            faceStatusText.value = 'Fingerprint matched. Capturing attendance photo...'
            await openCameraForCapture()
            const image = captureAttendanceImage()

            if (!image) {
                throw new Error('Camera photo could not be captured.')
            }

            await postZktecoBridgeCommand(
                `${props.zktecoBridgeUrl.replace(/\/$/, '')}/finalize-attendance`,
                {
                    command_id: commandId,
                    attendance_image: image,
                },
            )

            faceStatusText.value = 'Recording fingerprint attendance...'
            continue
        }

        if (status.state === 'success') {
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
                attendance_type:
                    status.attendance_type ||
                    attendanceType.value ||
                    inferredAttendanceType(),
            })
            resetAttendanceSelection()
            return
        }

        if (status.state === 'error') {
            throw new Error(status.message || 'Fingerprint attendance failed.')
        }
    }

    throw new Error('No fingerprint scan was received. Please try again.')
}

const startZktecoAttendanceScan = async (
    payload: Record<string, unknown>,
    options: { launchBridge?: boolean } = {},
): Promise<void> => {
    const attendanceUrl = `${props.zktecoBridgeUrl.replace(/\/$/, '')}/attendance`
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
): Promise<void> => {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload),
    })

    const bridgePayload = await response.json().catch(() => ({}))

    if (!response.ok) {
        throw new Error(
            bridgePayload.message || 'Unable to connect to Finger Scanner Bridge.',
        )
    }
}

const createOfflineId = (): string =>
    crypto.randomUUID?.() ??
    `offline-${Date.now()}-${Math.random().toString(36).slice(2)}`

const submitAttendance = async (
    employeeIdentifier: string,
    image: string,
    method: AttendanceMethod,
    employeeName?: string,
): Promise<void> => {
    const attendanceAction = attendanceType.value || inferredAttendanceType()

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
        occurredAt: new Date().toISOString(),
        employeeIdentifier,
        employeeName: employeeName || employeeIdentifier,
        attendanceMethod: method,
        attendanceType: attendanceAction,
        latitude: coords.value.latitude,
        longitude: coords.value.longitude,
        location: locationLabel(),
        locationSource: locationSource.value || 'live',
        imageBlob: base64ToBlob(image, 'image/jpeg'),
        imageFileName: `attendance_${Date.now()}.jpg`,
    })

    if (result.queued) {
        toast.add({
            severity: 'info',
            summary: 'Saved Offline',
            detail: result.message,
            life: 10000,
        })
        resetAttendanceSelection()
        return
    }

    toast.add({
        severity: 'success',
        summary: 'Success',
        detail: result.payload?.message ?? 'Attendance recorded successfully.',
        life: 5000,
    })

    announceAttendanceGreeting(result.payload?.greeting)
    resetAttendanceSelection()
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
    updateTime()
    interval = setInterval(updateTime, 1000)
    getLocation().catch(() => null)

    ensureRFIDFocus()

    focusInterval = setInterval(() => {
        ensureRFIDFocus()
    }, 1000)

    document.addEventListener('click', onDocumentClick)
    document.addEventListener('touchend', onDocumentClick)

    scheduleAutoFingerprintScan(1000)
})

onUnmounted(() => {
    if (stream) {
        stream.getTracks().forEach((track) => track.stop())
    }

    clearInterval(interval)
    clearInterval(focusInterval)
    clearAutoFingerprintScan()
    stopCamera()

    document.removeEventListener('click', onDocumentClick)
    document.removeEventListener('touchend', onDocumentClick)
    if (rfidTimeout) clearTimeout(rfidTimeout)
})
</script>

<template>
    <div
        v-if="showCamera"
        class="bg-brand-card rounded-[2.5rem] p-4 shadow-[12px_12px_0px_0px_#001e1d] border-2 border-brand-stroke relative overflow-hidden flex flex-col"
    >
        <div
            class="absolute top-8 left-8 z-10 bg-brand-stroke rounded-full px-4 py-2 flex items-center gap-2 shadow-lg"
        >
            <div class="w-2 h-2 rounded-full bg-brand-tertiary animate-pulse" />
            <span class="text-brand-headline text-xs font-bold tracking-widest">
                LIVE
            </span>
        </div>

        <div
            class="absolute top-8 right-8 z-10 bg-brand-card rounded-full px-4 py-2 flex items-center gap-2 shadow-lg border border-brand-stroke"
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
            <span class="text-brand-stroke text-xs font-bold tracking-widest">
                <template v-if="locationLoading">GPS...</template>
                <template v-else-if="locationError">GPS blocked</template>
                <template v-else-if="usingCachedLocation">Cached GPS</template>
                <template v-else-if="accuracyWarning">Low GPS</template>
                <template v-else-if="isLocationReady">GPS ready</template>
                <template v-else>GPS pending</template>
            </span>
        </div>

        <div
            class="relative aspect-video w-full overflow-hidden rounded-4xl border-2 border-brand-stroke bg-brand-stroke"
        >
            <!--            <div-->
            <!--                v-if="isLoading"-->
            <!--                class="absolute flex flex-col items-center gap-3 text-brand-paragraph"-->
            <!--            >-->
            <!--                <Camera class="w-10 h-10 animate-bounce text-brand-accent"/>-->
            <!--                <p class="text-sm font-bold uppercase tracking-widest">-->
            <!--                    Waking up lens...-->
            <!--                </p>-->
            <!--            </div>-->

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
                class="absolute inset-0 flex items-center justify-center bg-brand-stroke/90 px-6 text-center text-brand-headline"
            >
                <p class="text-sm font-semibold">
                    Camera blocked. Check browser permissions to proceed.
                </p>
            </div>
        </div>

        <p
            v-if="showCamera"
            class="text-brand-stroke text-sm text-center font-bold italic mt-5"
        >
            Look straight at the camera to record your attendance.
        </p>
    </div>

    <div class="flex flex-col gap-8">
        <div
            class="bg-brand-card rounded-4xl p-8 shadow-[8px_8px_0px_0px_#001e1d] border-2 border-brand-stroke shrink-0 animate-fade-up"
        >
            <div class="flex flex-col items-center text-center space-y-1 mb-8">
                <p
                    class="text-brand-bg font-bold tracking-wider uppercase text-xs"
                >
                    {{ currentDate }}
                </p>
                <h1
                    class="text-4xl lg:text-5xl font-black tracking-tight text-brand-stroke tabular-nums"
                >
                    {{ currentTime }}
                </h1>
            </div>

            <div class="w-full">
                <div class="grid grid-cols-2 gap-4">
                    <button
                        @click="handleTimeAction('time-in')"
                        :disabled="isProcessing"
                        class="group relative bg-brand-accent hover:bg-[#ffcf81] text-brand-stroke border-2 border-brand-stroke rounded-2xl py-4 px-3 transition-all duration-200 ease-out font-bold shadow-[4px_4px_0px_0px_#001e1d] hover:-translate-y-1 hover:shadow-[6px_6px_0px_0px_#001e1d] active:translate-x-1 active:translate-y-1 active:shadow-none flex flex-col items-center gap-2"
                        :class="{
                            'ring-4 ring-brand-stroke ring-offset-2 ring-offset-brand-card shadow-none translate-x-1 translate-y-1':
                                attendanceType === 'time-in',
                            'opacity-60 cursor-not-allowed hover:translate-y-0 hover:shadow-[4px_4px_0px_0px_#001e1d]':
                                isProcessing,
                        }"
                        :aria-pressed="attendanceType === 'time-in'"
                    >
                        <LogIn class="w-5 h-5" />
                        <span class="text-sm">Time In</span>
                        <span
                            v-if="attendanceType === 'time-in'"
                            class="absolute right-2 top-2 h-3 w-3 rounded-full border-2 border-brand-stroke bg-green-500"
                            aria-hidden="true"
                        />
                    </button>

                    <button
                        @click="handleTimeAction('time-out')"
                        :disabled="isProcessing"
                        class="group relative bg-brand-tertiary hover:bg-[#f07a7b] text-brand-headline border-2 border-brand-stroke rounded-2xl py-4 px-3 transition-all duration-200 ease-out font-bold shadow-[4px_4px_0px_0px_#001e1d] hover:-translate-y-1 hover:shadow-[6px_6px_0px_0px_#001e1d] active:translate-x-1 active:translate-y-1 active:shadow-none flex flex-col items-center gap-2"
                        :class="{
                            'ring-4 ring-brand-stroke ring-offset-2 ring-offset-brand-card shadow-none translate-x-1 translate-y-1':
                                attendanceType === 'time-out',
                            'opacity-60 cursor-not-allowed hover:translate-y-0 hover:shadow-[4px_4px_0px_0px_#001e1d]':
                                isProcessing,
                        }"
                        :aria-pressed="attendanceType === 'time-out'"
                    >
                        <LogOut class="w-5 h-5" />
                        <span class="text-sm">Time Out</span>
                        <span
                            v-if="attendanceType === 'time-out'"
                            class="absolute right-2 top-2 h-3 w-3 rounded-full border-2 border-brand-stroke bg-green-500"
                            aria-hidden="true"
                        />
                    </button>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-4">
                    <button
                        type="button"
                        class="inline-flex w-full items-center justify-center gap-2 bg-brand-card hover:bg-white text-brand-stroke border-2 border-brand-stroke rounded-2xl py-4 px-3 transition-all duration-200 ease-out font-bold shadow-[4px_4px_0px_0px_#001e1d] hover:-translate-y-1 hover:shadow-[6px_6px_0px_0px_#001e1d] active:translate-x-1 active:translate-y-1 active:shadow-none"
                        :class="{
                            'opacity-60 cursor-not-allowed hover:translate-y-0 hover:shadow-[4px_4px_0px_0px_#001e1d]':
                                isProcessing,
                        }"
                        :disabled="isProcessing"
                        title="Fingerprint"
                        @click="submitFingerprintAttendance"
                    >
                        <LoaderCircle
                            v-if="processingMethod === 'fingerprint'"
                            class="w-5 h-5 animate-spin"
                        />
                        <Fingerprint v-else class="w-5 h-5" />
                        <span class="text-sm">Fingerprint</span>
                    </button>

                    <button
                        type="button"
                        class="inline-flex w-full items-center justify-center gap-2 bg-brand-card hover:bg-white text-brand-stroke border-2 border-brand-stroke rounded-2xl py-4 px-3 transition-all duration-200 ease-out font-bold shadow-[4px_4px_0px_0px_#001e1d] hover:-translate-y-1 hover:shadow-[6px_6px_0px_0px_#001e1d] active:translate-x-1 active:translate-y-1 active:shadow-none"
                        :class="{
                            'opacity-60 cursor-not-allowed hover:translate-y-0 hover:shadow-[4px_4px_0px_0px_#001e1d]':
                                isProcessing,
                        }"
                        :disabled="isProcessing"
                        title="Facial Recognition"
                        @click="submitFaceAttendance"
                    >
                        <ScanFace class="w-5 h-5" />
                        <span class="text-sm">Face</span>
                    </button>
                </div>

                <div
                    v-if="isProcessing"
                    class="mt-5 flex items-center gap-3 rounded-2xl border-2 border-brand-stroke bg-brand-headline px-4 py-3 text-left shadow-[3px_3px_0px_0px_#001e1d]"
                >
                    <LoaderCircle class="h-5 w-5 shrink-0 animate-spin text-brand-stroke" />
                    <p
                        class="text-brand-stroke font-bold tracking-wider uppercase text-xs"
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

                        <div class="flex items-center justify-center gap-2">
                            <input
                                v-if="showEmployeeIdInputField"
                                ref="empIdInput"
                                v-model="employeePassword"
                                type="password"
                                name="keypad-attendance-password"
                                autocomplete="new-password"
                                autocapitalize="off"
                                spellcheck="false"
                                placeholder="Password"
                                class="text-brand-stroke border-2 border-brand-stroke rounded-xl py-3 px-3 text-sm w-full mt-4"
                                @focus="onEmpIdFocus"
                                @input="onEmpIdInput"
                                @keydown="onEmpIdKeydown"
                            />

                            <div v-if="showEmployeeIdInputField" class="mt-3">
                                <button
                                    type="button"
                                    class="w-full bg-brand-stroke hover:bg-brand-bg text-brand-headline border-2 border-brand-stroke rounded-xl py-3 px-4 text-sm font-bold transition-all duration-200 ease-out shadow-[3px_3px_0px_0px_#abd1c6] hover:-translate-y-0.5 hover:shadow-[5px_5px_0px_0px_#abd1c6] active:translate-x-1 active:translate-y-1 active:shadow-none"
                                    @click="submitManualAttendance"
                                >
                                    Submit
                                </button>
                            </div>
                        </div>
                    </div>

                    <p
                        v-if="
                            showEmployeeIdInputField ||
                            faceStatusText !== 'Face verification ready.'
                        "
                        class="text-brand-bg text-xs font-black uppercase tracking-wide"
                    >
                        {{ faceStatusText }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped></style>
