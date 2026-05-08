<script setup lang="ts">
import * as faceapi from 'face-api.js';
import axios from 'axios';
import {Camera, Fingerprint, LoaderCircle, LogIn, LogOut, MapPin, TriangleAlert} from "@lucide/vue";
import {computed, nextTick, onMounted, onUnmounted, ref, watch} from "vue";
import {router, usePage} from '@inertiajs/vue3'
import {useToast} from "primevue";
import {useGeolocator} from '@/Composables/useGeolocator.js';

const page = usePage();
const toast = useToast();
const {
    coords,
    error: locationError,
    loading: locationLoading,
    accuracyWarning,
    getLocation,
} = useGeolocator();


type AttendanceAction = "time-in" | "time-out"
type AttendanceMethod = "rfid" | "keypad" | "fingerprint"
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
    profile_url: string
}

const attendanceType = ref<AttendanceAction | ''>('')
const videoRef = ref<HTMLVideoElement | null>(null)
const overlayRef = ref<HTMLCanvasElement | null>(null)
const canvasRef = ref<HTMLCanvasElement | null>(null)
const isLoading = ref(false)
const isError = ref(false)
const isVideoReady = ref(false)
const isFaceModelReady = ref(false)
const faceStatusText = ref('Face verification ready.')

const currentTime = ref("")
const currentDate = ref("")

const showEmployeeIdInputField = ref(false);

const rfidInput = ref<HTMLInputElement | null>(null)
const empIdInput = ref<HTMLInputElement | null>(null)
const rfidBuffer = ref('')
const manualEmployeeId = ref('')

let stream: MediaStream | null = null
let interval: ReturnType<typeof setInterval>
let focusInterval: ReturnType<typeof setInterval>
let faceDetectionInterval: ReturnType<typeof setInterval> | null = null
let rfidTimeout: any = null
const registeredFaceDescriptors = new Map<string, Float32Array>()

const lastScannedTime = ref(0)
const SCAN_COOLDOWN_MS = 1000
const FACE_MODEL_PATH = '/models/face-api'
const FACE_MATCH_THRESHOLD = 0.52
const faceDetectorOptions = new faceapi.TinyFaceDetectorOptions({
    inputSize: 416,
    scoreThreshold: 0.5,
})
const isLocationReady = computed(() => Boolean(coords.value)
    && Number.isFinite(coords.value.latitude)
    && Number.isFinite(coords.value.longitude)
    && !locationError.value)
const showCamera = computed(() => showEmployeeIdInputField.value)

const employeeFullName = (employee: VerifiedEmployee): string => (
    `${employee.first_name} ${employee.last_name}`.trim()
)

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
            throw new Error("getUserMedia is not available in this browser/context.")
        }

        isError.value = false
        isLoading.value = true

        stream = await navigator.mediaDevices.getUserMedia({
            video: {
                width: {ideal: 1280},
                height: {ideal: 720},
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
        console.error("Camera error:", error)
        isLoading.value = false
        isError.value = true
    }
}

const stopCamera = (): any => {
    stream?.getTracks().forEach(track => track.stop())
    stream = null
    if (videoRef.value) videoRef.value.srcObject = null
    clearFaceDetectorOverlay()
    isVideoReady.value = false
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

const drawFaceDetectorOverlay = async () => {
    if (!videoRef.value || !overlayRef.value || !isVideoReady.value || !isFaceModelReady.value) return
    if (videoRef.value.paused || videoRef.value.ended) return

    const video = videoRef.value
    const canvas = overlayRef.value
    const displaySize = {
        width: video.clientWidth,
        height: video.clientHeight,
    }

    if (!displaySize.width || !displaySize.height) return

    faceapi.matchDimensions(canvas, displaySize)

    const detections = await faceapi
        .detectAllFaces(video, faceDetectorOptions)
        .withFaceLandmarks()

    const resizedDetections = faceapi.resizeResults(detections, displaySize)
    const context = canvas.getContext('2d')
    context?.clearRect(0, 0, canvas.width, canvas.height)

    resizedDetections.forEach((detection, index) => {
        const drawBox = new faceapi.draw.DrawBox(detection.detection.box, {
            label: detections.length === 1 ? 'Face detected' : `Face ${index + 1}`,
            boxColor: '#f9bc60',
            lineWidth: 3,
        })

        drawBox.draw(canvas)
    })
}

const startFaceDetectorOverlay = () => {
    clearFaceDetectorOverlay()

    faceDetectionInterval = setInterval(() => {
        drawFaceDetectorOverlay().catch((error) => {
            console.error('Face detector overlay failed:', error)
            clearFaceDetectorOverlay()
        })
    }, 900)
}

const captureImage = (): string | null => {
    if (!videoRef.value || !canvasRef.value || !isVideoReady.value) return null

    const video = videoRef.value
    const canvas = canvasRef.value

    canvas.width = video.videoWidth
    canvas.height = video.videoHeight

    const ctx = canvas.getContext('2d')
    if (!ctx) return null

    ctx.drawImage(video, 0, 0, canvas.width, canvas.height)

    // Returns base64 image string
    return canvas.toDataURL('image/jpeg', 0.8)
}

const updateTime = () => {
    const now = new Date()

    currentTime.value = now.toLocaleTimeString("en-US", {
        hour: "numeric",
        minute: "2-digit",
        second: "2-digit",
        hour12: true,
    })

    currentDate.value = now.toLocaleDateString("en-US", {
        weekday: "long",
        year: "numeric",
        month: "long",
        day: "numeric",
    })
}

const inferredAttendanceType = (): AttendanceAction => {
    const now = new Date()
    const minutesFromMidnight = now.getHours() * 60 + now.getMinutes()

    return minutesFromMidnight <= 16 * 60 ? 'time-in' : 'time-out'
}

const ensureAttendanceFlowReady = async (actionName?: AttendanceAction) => {
    attendanceType.value = actionName || attendanceType.value || inferredAttendanceType()
    showEmployeeIdInputField.value = true
    await nextTick()
    await initializeCamera()
    await loadFaceModels().catch((error) => {
        console.error('Face model load failed:', error)
        faceStatusText.value = 'Face verification failed to load.'
    })
    if (isFaceModelReady.value) startFaceDetectorOverlay()
    forceRFIDFocus()
}

const handleTimeAction = async (actionName: AttendanceAction) => {
    await ensureAttendanceFlowReady(actionName)
}

const resetAttendanceSelection = () => {
    attendanceType.value = ''
    showEmployeeIdInputField.value = false
    manualEmployeeId.value = ''
    isLoading.value = false
    faceStatusText.value = 'Face verification ready.'
    stopCamera()
    setTimeout(() => forceRFIDFocus(), 50)
}

const announceAttendanceGreeting = (greeting?: AttendanceGreeting) => {
    if (!greeting?.first_name) return

    window.dispatchEvent(new CustomEvent('attendance:greeting', {
        detail: greeting,
    }))
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

const onEmpIdKeydown = (e: KeyboardEvent) => {
    if (e.key !== 'Enter') return
    e.preventDefault()

    submitManualAttendance()
}

const csrfToken = (): string => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''

const decodeWebAuthn = (input: string): Uint8Array => {
    input = input.replace(/-/g, '+').replace(/_/g, '/')
    const pad = input.length % 4
    if (pad) input += '='.repeat(4 - pad)

    return Uint8Array.from(atob(input), char => char.charCodeAt(0))
}

const encodeWebAuthn = (buffer: ArrayBuffer): string => btoa(String.fromCharCode(...new Uint8Array(buffer)))

const parseWebAuthnOptions = (publicKey: any): PublicKeyCredentialRequestOptions | PublicKeyCredentialCreationOptions => {
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

    for (const key of ['clientDataJSON', 'attestationObject', 'authenticatorData', 'signature', 'userHandle']) {
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

const postWebAuthnJson = async (url: string, data: Record<string, any> = {}): Promise<any> => {
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
        throw new Error(payload.message || Object.values(payload.errors ?? {})?.[0]?.[0] || 'Fingerprint verification failed.')
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

        console.log('Camera not ready. Please allow camera access.')
        return null
    }

    return image
}

const verifyEmployeeIdentifier = async (employeeIdentifier: string): Promise<VerifiedEmployee | null> => {
    try {
        faceStatusText.value = 'Checking employee ID...'

        const response = await axios.post('/attendance/verify-employee', {
            employee_id: employeeIdentifier,
        })

        return response.data.employee as VerifiedEmployee
    } catch (error: any) {
        const message = error?.response?.data?.message
            ?? Object.values(error?.response?.data?.errors ?? {})?.[0]?.[0]
            ?? 'Employee ID is not existing.'

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

const getRegisteredFaceDescriptor = async (employee: VerifiedEmployee): Promise<Float32Array | null> => {
    const cachedDescriptor = registeredFaceDescriptors.get(employee.employee_id)
    if (cachedDescriptor) return cachedDescriptor

    const image = await faceapi.fetchImage(employee.profile_url)
    const detection = await faceapi
        .detectSingleFace(image, faceDetectorOptions)
        .withFaceLandmarks()
        .withFaceDescriptor()

    if (!detection) return null

    registeredFaceDescriptors.set(employee.employee_id, detection.descriptor)

    return detection.descriptor
}

const verifyLiveFaceMatchesEmployee = async (employee: VerifiedEmployee): Promise<boolean> => {
    if (!videoRef.value || !isVideoReady.value) {
        toast.add({
            severity: 'error',
            summary: 'Camera',
            detail: 'Camera not ready. Please allow camera access.',
            life: 5000,
        })
        return false
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
            return false
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
            return false
        }

        const distance = Math.min(
            ...detections.map((detection) => faceapi.euclideanDistance(registeredDescriptor, detection.descriptor)),
        )
        const isMatch = distance <= FACE_MATCH_THRESHOLD

        if (!isMatch) {
            toast.add({
                severity: 'error',
                summary: 'Face Verification',
                detail: `Face does not match ${employeeFullName(employee)}.`,
                life: 6000,
            })
            faceStatusText.value = `Face mismatch. Distance: ${distance.toFixed(2)}.`
            return false
        }

        faceStatusText.value = `Face matched ${employeeFullName(employee)}.`
        return true
    } catch (error) {
        console.error('Face verification failed:', error)
        toast.add({
            severity: 'error',
            summary: 'Face Verification',
            detail: 'Unable to verify face. Check lighting and registered face image.',
            life: 6000,
        })
        faceStatusText.value = 'Unable to verify face.'
        return false
    }
}

const verifyEmployeeFaceAndSubmit = async (employeeIdentifier: string, method: AttendanceMethod): Promise<void> => {
    const employee = await verifyEmployeeIdentifier(employeeIdentifier)
    if (!employee) return

    const isFaceMatch = await verifyLiveFaceMatchesEmployee(employee)
    if (!isFaceMatch) {
        isLoading.value = false
        setTimeout(() => forceRFIDFocus(), 50)
        return
    }

    const image = captureAttendanceImage()
    if (!image) {
        isLoading.value = false
        return
    }

    await submitAttendance(employee.employee_id, image, method)
}

const submitRFIDAttendance = async (rfid: any) => {
    const scannedRfid = rfid?.trim()

    rfidBuffer.value = ''
    if (rfidInput.value) rfidInput.value.value = ''

    setTimeout(() => ensureRFIDFocus(), 50)

    if (!scannedRfid) {
        console.log('No RFID data provided:', rfid)
        return
    }

    await ensureAttendanceFlowReady()

    const now = Date.now()
    if (now - lastScannedTime.value < SCAN_COOLDOWN_MS) {
        console.log('Scan cooldown active, ignoring scan')
        return
    }
    lastScannedTime.value = now

    console.log('Processing RFID scan:', scannedRfid)

    try {
        isLoading.value = true
        console.log('Verifying RFID attendance...')
        await verifyEmployeeFaceAndSubmit(scannedRfid, 'rfid')
    } catch (e) {
        console.error('Error submitting RFID attendance:', e)
        isLoading.value = false
    }
}

const submitManualAttendance = async () => {
    const employeeId = manualEmployeeId.value.trim()

    if (!employeeId) {
        toast.add({
            severity: 'warn',
            summary: 'Warning',
            detail: 'Enter employee ID first.',
            life: 5000,
        })
        return
    }

    await ensureAttendanceFlowReady()

    try {
        isLoading.value = true
        console.log('Verifying keypad attendance...');
        await verifyEmployeeFaceAndSubmit(employeeId, 'keypad')
        manualEmployeeId.value = ''
        setTimeout(() => forceRFIDFocus(), 50)
    } catch (e) {
        console.error('Error submitting keypad attendance:', e);
        isLoading.value = false
    }
}

const submitFingerprintAttendance = async () => {
    const attendanceAction = attendanceType.value || inferredAttendanceType()
    attendanceType.value = attendanceAction

    if (typeof PublicKeyCredential === 'undefined') {
        toast.add({
            severity: 'error',
            summary: 'Fingerprint',
            detail: 'This browser does not support fingerprint authentication.',
            life: 5000,
        })
        return
    }

    await ensureAttendanceFlowReady(attendanceAction)

    const image = captureAttendanceImage()
    if (!image) {
        return
    }

    if (locationLoading.value || locationError.value || !isLocationReady.value) {
        toast.add({
            severity: 'error',
            summary: 'Location',
            detail: locationError.value || 'Waiting for GPS location.',
            life: 5000,
        })
        return
    }

    try {
        isLoading.value = true
        faceStatusText.value = 'Waiting for fingerprint verification...'

        const options = await postWebAuthnJson('/attendance/fingerprint/options')
        const credential = await navigator.credentials.get({
            publicKey: parseWebAuthnOptions(options) as PublicKeyCredentialRequestOptions,
        })

        const payload = await postWebAuthnJson('/attendance/fingerprint/record', {
            ...parseWebAuthnCredential(credential),
            attendance_type: attendanceAction,
            attendance_image: image,
            latitude: coords.value.latitude,
            longitude: coords.value.longitude,
        })

        toast.add({
            severity: 'success',
            summary: 'Success',
            detail: payload.message ?? 'Attendance recorded successfully.',
            life: 5000,
        })

        announceAttendanceGreeting(payload.greeting)
        resetAttendanceSelection()
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Fingerprint',
            detail: error instanceof Error ? error.message : 'Fingerprint attendance failed.',
            life: 5000,
        })
        resetAttendanceSelection()
    }
}

const submitAttendance = (employeeIdentifier: string, image: string, method: AttendanceMethod): Promise<void> => {
    return new Promise((resolve, reject) => {
        const attendanceAction = attendanceType.value || inferredAttendanceType()
        attendanceType.value = attendanceAction

        if (locationLoading.value || locationError.value || !isLocationReady.value) {
            toast.add({
                severity: 'error',
                summary: 'Location',
                detail: locationError.value || 'Waiting for GPS location.',
                life: 5000,
            })

            reject(new Error('Location is not ready.'))
            return
        }

        const formData = new FormData()

        // Keep "rfid" for the existing backend contract. attendance_method
        // separates RFID, keypad, and fingerprint submissions for future handling.
        formData.append('rfid', employeeIdentifier)
        formData.append('attendance_method', method)
        formData.append('attendance_type', attendanceAction)
        formData.append('latitude', String(coords.value.latitude))
        formData.append('longitude', String(coords.value.longitude))

        const blob = base64ToBlob(image, 'image/jpeg')
        formData.append('attendance-image', blob, `attendance_${Date.now()}.jpg`)

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')

        if (csrfToken) {
            formData.append('_token', csrfToken)
        }

        isLoading.value = true

        router.post('/attendance/record-time-in', formData, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                const flash = page.props.flash as any

                if (flash?.error) {
                    toast.add({
                        severity: 'error',
                        summary: 'Error',
                        detail: flash.error,
                        life: 5000,
                    })
                    resetAttendanceSelection()

                    reject(new Error(flash.error))
                    return
                }

                toast.add({
                    severity: 'success',
                    summary: 'Success',
                    detail: flash?.success ?? 'Attendance recorded successfully.',
                    life: 5000,
                })

                announceAttendanceGreeting(flash?.greeting)
                resetAttendanceSelection()

                resolve()
            },
            onError: (errors) => {
                toast.add({
                    severity: 'error',
                    summary: 'Error',
                    detail: Object.values(errors)[0] ?? 'Failed to record attendance.',
                    life: 5000,
                })
                resetAttendanceSelection()

                reject(new Error('Validation error.'))
            },
        })
    })
}
const base64ToBlob = (base64: string, mimeType: string): Blob => {
    const byteString = atob(base64.split(',')[1])
    const buffer = new Uint8Array(byteString.length)

    for (let i = 0; i < byteString.length; i++) {
        buffer[i] = byteString.charCodeAt(i)
    }

    return new Blob([buffer], {type: mimeType})
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
    }, 300)

    document.addEventListener('click', onDocumentClick)
    document.addEventListener('touchend', onDocumentClick)
})

onUnmounted(() => {
    if (stream) {
        stream.getTracks().forEach(track => track.stop())
    }

    clearInterval(interval)
    clearInterval(focusInterval)
    stopCamera()

    document.removeEventListener('click', onDocumentClick)
    document.removeEventListener('touchend', onDocumentClick)
    if (rfidTimeout) clearTimeout(rfidTimeout)
})
</script>

<template>
    <div
        v-if="showCamera"
        class="bg-brand-card rounded-[2.5rem] p-4 shadow-[12px_12px_0px_0px_#001e1d] border-2 border-brand-stroke relative overflow-hidden flex flex-col h-65 sm:h-80 lg:h-96"
    >
        <div
            class="absolute top-8 left-8 z-10 bg-brand-stroke rounded-full px-4 py-2 flex items-center gap-2 shadow-lg"
        >
            <div class="w-2 h-2 rounded-full bg-brand-tertiary animate-pulse"></div>
            <span class="text-brand-headline text-xs font-bold tracking-widest">
                LIVE
            </span>
        </div>

        <div
            class="absolute top-8 right-8 z-10 bg-brand-card rounded-full px-4 py-2 flex items-center gap-2 shadow-lg border border-brand-stroke"
        >
            <LoaderCircle v-if="locationLoading" class="h-4 w-4 animate-spin text-brand-stroke"/>
            <TriangleAlert v-else-if="locationError" class="h-4 w-4 text-red-600"/>
            <TriangleAlert v-else-if="accuracyWarning" class="h-4 w-4 text-yellow-600"/>
            <MapPin v-else class="h-4 w-4 text-green-600"/>
            <span class="text-brand-stroke text-xs font-bold tracking-widest">
                <template v-if="locationLoading">GPS...</template>
                <template v-else-if="locationError">GPS blocked</template>
                <template v-else-if="accuracyWarning">Low GPS</template>
                <template v-else-if="isLocationReady">GPS ready</template>
                <template v-else>GPS pending</template>
            </span>
        </div>

        <div
            class="w-full h-full rounded-4xl bg-brand-stroke overflow-hidden relative flex items-center justify-center"
        >
            <div
                v-if="isLoading"
                class="absolute flex flex-col items-center gap-3 text-brand-paragraph"
            >
                <Camera class="w-10 h-10 animate-bounce text-brand-accent"/>
                <p class="text-sm font-bold uppercase tracking-widest">
                    Waking up lens...
                </p>
            </div>

            <video
                ref="videoRef"
                autoplay
                playsinline
                muted
                class="home-camera-video h-full w-full rounded-2xl border-2 border-brand-stroke object-cover"
                :class="{ loaded: isVideoReady }"
            ></video>

            <canvas ref="overlayRef" class="absolute inset-0 h-full w-full rounded-2xl pointer-events-none"/>
            <canvas ref="canvasRef" style="display: none;"/>

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
            v-if="showEmployeeIdInputField"
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
                <p class="text-brand-bg font-bold tracking-wider uppercase text-xs">
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
                        class="group relative bg-brand-accent hover:bg-[#ffcf81] text-brand-stroke border-2 border-brand-stroke rounded-2xl py-4 px-3 transition-all font-bold shadow-[4px_4px_0px_0px_#001e1d] active:translate-x-1 active:translate-y-1 active:shadow-none flex flex-col items-center gap-2"
                    >
                        <LogIn class="w-5 h-5"/>
                        <span class="text-sm">Time In</span>
                    </button>

                    <button
                        @click="handleTimeAction('time-out')"
                        class="group relative bg-brand-tertiary hover:bg-[#f07a7b] text-brand-headline border-2 border-brand-stroke rounded-2xl py-4 px-3 transition-all font-bold shadow-[4px_4px_0px_0px_#001e1d] active:translate-x-1 active:translate-y-1 active:shadow-none flex flex-col items-center gap-2"
                    >
                        <LogOut class="w-5 h-5"/>
                        <span class="text-sm">Time Out</span>
                    </button>
                </div>

                <button
                    type="button"
                    class="mt-4 inline-flex w-full items-center justify-center gap-2 bg-brand-card text-brand-stroke border-2 border-brand-stroke rounded-2xl py-4 px-3 transition-all font-bold shadow-[4px_4px_0px_0px_#001e1d] active:translate-x-1 active:translate-y-1 active:shadow-none"
                    title="Fingerprint"
                    @click="submitFingerprintAttendance"
                >
                    <Fingerprint class="w-5 h-5"/>
                    <span class="text-sm">Fingerprint</span>
                </button>

                <div v-if="isLoading" class="mt-5">
                    <p class="text-brand-bg font-bold tracking-wider uppercase text-xs">
                        Processing, please wait...
                    </p>
                </div>

                <div class="flex flex-col justify-center gap-3" v-else>
                    <div class="w-full">
                        <input
                            ref="rfidInput"
                            type="text"
                            autocomplete="off"
                            class="absolute -top-96"
                            style="opacity: 0; pointer-events: none;"
                            @input="onRFIDInput"
                            @keydown="onRFIDKeydown"
                        />

                        <div class="flex items-center justify-center gap-2">
                            <input
                                v-if="showEmployeeIdInputField"
                                ref="empIdInput"
                                v-model="manualEmployeeId"
                                type="text"
                                placeholder="Employee ID"
                                class="text-brand-stroke border-2 border-brand-stroke rounded-xl py-3 px-3 text-sm w-full mt-4"
                                @focus="onEmpIdFocus"
                                @keydown="onEmpIdKeydown"
                            />

                            <div
                                v-if="showEmployeeIdInputField"
                                class="mt-3"
                            >
                                <button
                                    type="button"
                                    class="w-full bg-brand-stroke text-brand-headline border-2 border-brand-stroke rounded-xl py-3 px-4 text-sm font-bold shadow-[3px_3px_0px_0px_#abd1c6] active:translate-x-1 active:translate-y-1 active:shadow-none"
                                    @click="submitManualAttendance"
                                >
                                    Submit
                                </button>
                            </div>
                        </div>
                    </div>

                    <p
                        v-if="showEmployeeIdInputField || faceStatusText !== 'Face verification ready.'"
                        class="text-brand-bg text-xs font-black uppercase tracking-wide"
                    >
                        {{ faceStatusText }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>

</style>
