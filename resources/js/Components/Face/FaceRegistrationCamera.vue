<script setup lang="ts">
import * as faceapi from 'face-api.js'
import {
    Camera,
    CheckCircle2,
    Save,
    ScanFace,
    RotateCcw,
    UserRound,
    UsersRound,
} from '@lucide/vue'
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { useToast } from 'primevue'
import { mapFaceBoxToObjectCover } from '@/Utils/faceOverlay.js'

type FaceEmployee = {
    id: number
    employee_id: string
    first_name: string
    last_name: string
    position: string
    profile_url: string
}

const props = defineProps<{
    employees: FaceEmployee[]
}>()

const page = usePage()
const toast = useToast()

const videoRef = ref<HTMLVideoElement | null>(null)
const overlayRef = ref<HTMLCanvasElement | null>(null)
const captureCanvasRef = ref<HTMLCanvasElement | null>(null)

const selectedEmployeeId = ref(props.employees[0]?.employee_id ?? '')
const statusText = ref('Loading face models...')
const isCameraReady = ref(false)
const isModelReady = ref(false)
const isSubmitting = ref(false)
const isCapturing = ref(false)
const isReviewingCapture = ref(false)
const faceCount = ref(0)
const captureCountdown = ref(0)
const capturedPreview = ref('')
const capturedImage = ref<Blob | null>(null)
const existingFaceDescriptors = new Map<string, Float32Array>()

let stream: MediaStream | null = null
let scanInterval: ReturnType<typeof setInterval> | null = null

const modelPath = '/models/face-api'
const detectorOptions = computed(
    () =>
        new faceapi.TinyFaceDetectorOptions({
            inputSize: 416,
            scoreThreshold: 0.5,
        }),
)

const selectedEmployee = computed(
    () =>
        props.employees.find(
            (employee) => employee.employee_id === selectedEmployeeId.value,
        ) ?? null,
)

const canSave = computed(() =>
    Boolean(
        selectedEmployee.value &&
        faceCount.value === 1 &&
        capturedImage.value &&
        isReviewingCapture.value &&
        !isSubmitting.value,
    ),
)

const employeeName = (employee: FaceEmployee) =>
    `${employee.first_name} ${employee.last_name}`.trim()

const loadModels = async () => {
    await Promise.all([
        faceapi.nets.tinyFaceDetector.loadFromUri(modelPath),
        faceapi.nets.faceLandmark68Net.loadFromUri(modelPath),
        faceapi.nets.faceRecognitionNet.loadFromUri(modelPath),
    ])

    isModelReady.value = true
    statusText.value = 'Start the camera and center one face.'
}

const loadExistingFaceDescriptors = async () => {
    existingFaceDescriptors.clear()

    for (const employee of props.employees) {
        if (!employee.profile_url) continue

        try {
            const image = await faceapi.fetchImage(employee.profile_url)
            const detection = await faceapi
                .detectSingleFace(image, detectorOptions.value)
                .withFaceLandmarks()
                .withFaceDescriptor()

            if (!detection) continue

            existingFaceDescriptors.set(
                employee.employee_id,
                detection.descriptor,
            )
        } catch (error) {
            console.error(
                `Unable to read registered face for ${employee.employee_id}`,
                error,
            )
        }
    }
}

const startCamera = async () => {
    if (!navigator.mediaDevices?.getUserMedia) {
        throw new Error(
            'Camera access is not available in this browser or context.',
        )
    }

    stream = await navigator.mediaDevices.getUserMedia({
        video: {
            width: { ideal: 1280 },
            height: { ideal: 720 },
            facingMode: 'user',
        },
        audio: false,
    })

    if (!videoRef.value) return

    videoRef.value.srcObject = stream

    await new Promise<void>((resolve) => {
        if (!videoRef.value) return resolve()

        videoRef.value.onloadedmetadata = () => {
            videoRef.value?.play()
            isCameraReady.value = true
            resolve()
        }
    })
}

const stopCamera = () => {
    stream?.getTracks().forEach((track) => track.stop())
    stream = null
    isCameraReady.value = false
}

const scanFace = async () => {
    if (
        !videoRef.value ||
        !overlayRef.value ||
        !isCameraReady.value ||
        !isModelReady.value ||
        isReviewingCapture.value ||
        isCapturing.value ||
        isSubmitting.value
    )
        return

    const video = videoRef.value
    const canvas = overlayRef.value
    const displaySize = {
        width: video.clientWidth,
        height: video.clientHeight,
    }

    if (!displaySize.width || !displaySize.height) return

    faceapi.matchDimensions(canvas, displaySize)

    const detections = await faceapi
        .detectAllFaces(video, detectorOptions.value)
        .withFaceLandmarks()
        .withFaceDescriptors()

    faceCount.value = detections.length

    const context = canvas.getContext('2d')
    context?.clearRect(0, 0, canvas.width, canvas.height)

    detections.forEach((detection, index) => {
        const box = mapFaceBoxToObjectCover(detection.detection.box, video)
        if (!box) return

        const drawBox = new faceapi.draw.DrawBox(
            box,
            {
                label: index === 0 ? 'Enrollment face' : 'Extra face',
                boxColor: detections.length === 1 ? '#f9bc60' : '#e16162',
                lineWidth: 3,
            },
        )

        drawBox.draw(canvas)
    })

    if (!detections.length) {
        statusText.value = 'No face detected.'
        return
    }

    statusText.value =
        detections.length === 1
            ? 'One face detected. Capturing for review...'
            : 'Keep only one face in frame.'

    if (detections.length === 1) {
        await captureForReview()
    }
}

const startScanLoop = () => {
    if (scanInterval) clearInterval(scanInterval)

    scanInterval = setInterval(() => {
        scanFace().catch((error) => {
            console.error('Face enrollment scan failed.', error)
            statusText.value =
                'Face scan failed. Check lighting and camera access.'
        })
    }, 700)
}

const captureBlob = (): Blob | null => {
    if (!videoRef.value || !captureCanvasRef.value) return null

    const video = videoRef.value
    const canvas = captureCanvasRef.value
    canvas.width = video.videoWidth
    canvas.height = video.videoHeight

    const context = canvas.getContext('2d')
    if (!context) return null

    context.drawImage(video, 0, 0, canvas.width, canvas.height)
    capturedPreview.value = canvas.toDataURL('image/jpeg', 0.9)

    const byteString = atob(capturedPreview.value.split(',')[1])
    const buffer = new Uint8Array(byteString.length)

    for (let i = 0; i < byteString.length; i++) {
        buffer[i] = byteString.charCodeAt(i)
    }

    return new Blob([buffer], { type: 'image/jpeg' })
}

const waitForCaptureCountdown = async () => {
    for (let seconds = 3; seconds > 0; seconds--) {
        captureCountdown.value = seconds
        statusText.value = `Hold still. Capturing in ${seconds}...`
        await new Promise((resolve) => setTimeout(resolve, 1000))

        if (
            !isCapturing.value ||
            isReviewingCapture.value ||
            isSubmitting.value ||
            !isCameraReady.value
        ) {
            captureCountdown.value = 0
            return false
        }
    }

    captureCountdown.value = 0
    return true
}

const findDuplicateFace = async (): Promise<FaceEmployee | null> => {
    if (!videoRef.value || !selectedEmployee.value) return null

    const detection = await faceapi
        .detectSingleFace(videoRef.value, detectorOptions.value)
        .withFaceLandmarks()
        .withFaceDescriptor()

    if (!detection) return null

    let duplicate: FaceEmployee | null = null
    let bestDistance = Number.POSITIVE_INFINITY

    for (const employee of props.employees) {
        if (employee.employee_id === selectedEmployee.value.employee_id)
            continue

        const descriptor = existingFaceDescriptors.get(employee.employee_id)
        if (!descriptor) continue

        const distance = faceapi.euclideanDistance(
            descriptor,
            detection.descriptor,
        )
        if (distance > 0.52 || distance >= bestDistance) continue

        duplicate = employee
        bestDistance = distance
    }

    return duplicate
}

const saveRegistration = async () => {
    if (!selectedEmployee.value) {
        toast.add({
            severity: 'warn',
            summary: 'Employee',
            detail: 'Select an employee first.',
            life: 4000,
        })
        return
    }

    if (faceCount.value !== 1) {
        toast.add({
            severity: 'warn',
            summary: 'Face Registration',
            detail: 'Keep exactly one face in the camera before saving.',
            life: 5000,
        })
        return
    }

    if (!capturedImage.value) {
        toast.add({
            severity: 'error',
            summary: 'Camera',
            detail: 'Capture a face image before saving.',
            life: 5000,
        })
        return
    }

    const formData = new FormData()
    formData.append('employee_id', selectedEmployee.value.employee_id)
    formData.append(
        'face-image',
        capturedImage.value,
        `face_registration_${selectedEmployee.value.employee_id}.jpg`,
    )

    isSubmitting.value = true
    statusText.value = `Saving face for ${employeeName(selectedEmployee.value)}...`

    router.post('/face/register', formData, {
        preserveScroll: true,
        onSuccess: () => {
            const flash = page.props.flash as any

            toast.add({
                severity: 'success',
                summary: 'Face Registration',
                detail: flash?.success ?? 'Face registered successfully.',
                life: 5000,
            })

            statusText.value = 'Face saved. You can register another employee.'
            capturedImage.value = null
            capturedPreview.value = ''
            isReviewingCapture.value = false
        },
        onError: (errors) => {
            toast.add({
                severity: 'error',
                summary: 'Face Registration',
                detail:
                    Object.values(errors)[0] ??
                    'Unable to save face registration.',
                life: 5000,
            })
            statusText.value = 'Unable to save face registration.'
        },
        onFinish: () => {
            isSubmitting.value = false
        },
    })
}

const captureForReview = async () => {
    if (isCapturing.value || isReviewingCapture.value || isSubmitting.value)
        return

    isCapturing.value = true
    const countdownCompleted = await waitForCaptureCountdown()
    if (!countdownCompleted) {
        isCapturing.value = false
        return
    }

    const duplicate = await findDuplicateFace()
    if (duplicate) {
        toast.add({
            severity: 'error',
            summary: 'Face Registration',
            detail: `This face is already registered to ${employeeName(duplicate)}.`,
            life: 6000,
        })
        statusText.value = 'Duplicate registered face detected.'
        isCapturing.value = false
        return
    }

    const image = captureBlob()
    if (!image) {
        toast.add({
            severity: 'error',
            summary: 'Camera',
            detail: 'Camera image is not ready.',
            life: 5000,
        })
        isCapturing.value = false
        return
    }

    capturedImage.value = image
    isReviewingCapture.value = true
    isCapturing.value = false
    statusText.value = 'Review the captured face image.'

    if (scanInterval) {
        clearInterval(scanInterval)
        scanInterval = null
    }
}

const retakeRegistration = () => {
    capturedImage.value = null
    capturedPreview.value = ''
    isReviewingCapture.value = false
    isCapturing.value = false
    captureCountdown.value = 0
    statusText.value = 'Center one face in the camera.'
    startScanLoop()
}

watch(selectedEmployeeId, () => {
    capturedPreview.value = ''
    capturedImage.value = null
    isReviewingCapture.value = false
    isCapturing.value = false
    captureCountdown.value = 0
    startScanLoop()
})

onMounted(async () => {
    try {
        await loadModels()
        await loadExistingFaceDescriptors()
        await nextTick()
        await startCamera()
        startScanLoop()
    } catch (error) {
        console.error(error)
        statusText.value =
            error instanceof Error
                ? error.message
                : 'Unable to start face registration.'
        toast.add({
            severity: 'error',
            summary: 'Face Registration',
            detail: statusText.value,
            life: 7000,
        })
    }
})

onUnmounted(() => {
    stopCamera()
    captureCountdown.value = 0

    if (scanInterval) clearInterval(scanInterval)
})
</script>

<template>
    <section
        class="mx-auto grid max-w-7xl gap-6 lg:grid-cols-[minmax(0,1fr)_380px]"
    >
        <div
            class="rounded-3xl border-2 border-brand-stroke bg-brand-card p-4 shadow-[8px_8px_0px_0px_#001e1d]"
        >
            <div
                class="relative aspect-video overflow-hidden rounded-2xl border-2 border-brand-stroke bg-brand-stroke"
            >
                <div
                    v-if="!isCameraReady"
                    class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 bg-brand-stroke text-brand-headline"
                >
                    <Camera
                        class="h-10 w-10 animate-bounce text-brand-accent"
                    />
                    <p class="text-sm font-black uppercase tracking-widest">
                        {{ statusText }}
                    </p>
                </div>

                <video
                    ref="videoRef"
                    autoplay
                    muted
                    playsinline
                    class="h-full w-full object-cover"
                    :class="{
                        'opacity-100': isCameraReady && !isReviewingCapture,
                        'opacity-0': !isCameraReady || isReviewingCapture,
                    }"
                />

                <canvas
                    v-show="!isReviewingCapture"
                    ref="overlayRef"
                    class="absolute inset-0 h-full w-full"
                />
                <canvas ref="captureCanvasRef" class="hidden" />

                <div
                    v-if="captureCountdown > 0 && !isReviewingCapture"
                    class="absolute inset-0 z-20 flex flex-col items-center justify-center bg-black/45 text-brand-headline"
                >
                    <div
                        class="flex h-28 w-28 items-center justify-center rounded-full border-4 border-brand-accent bg-brand-stroke text-6xl font-black shadow-2xl"
                    >
                        {{ captureCountdown }}
                    </div>
                    <p class="mt-4 text-sm font-black uppercase tracking-widest">
                        Hold still
                    </p>
                </div>

                <div
                    v-if="isReviewingCapture"
                    class="absolute inset-0 flex flex-col bg-brand-stroke"
                >
                    <div
                        class="flex items-center justify-between border-b border-white/10 px-5 py-4"
                    >
                        <div>
                            <p
                                class="text-xs font-black uppercase tracking-wide text-brand-accent"
                            >
                                Captured
                            </p>
                            <p class="text-sm font-bold text-brand-headline">
                                Review this photo before saving
                            </p>
                        </div>
                        <div
                            class="rounded-full bg-green-500/20 px-3 py-1 text-xs font-black text-green-200"
                        >
                            Clear face
                        </div>
                    </div>

                    <div
                        class="flex min-h-0 flex-1 items-center justify-center p-6"
                    >
                        <img
                            :src="capturedPreview"
                            alt="Captured face preview"
                            class="max-h-full max-w-full rounded-2xl border-2 border-brand-headline bg-white object-contain shadow-2xl"
                        />
                    </div>

                    <div
                        class="border-t border-white/10 bg-black/30 px-5 py-3 text-center text-sm font-bold text-brand-headline/80"
                    >
                        Save this image if the face is clear, centered, and
                        unobstructed.
                    </div>
                </div>

                <div
                    class="absolute left-4 top-4 flex items-center gap-2 rounded-full border border-brand-stroke bg-brand-card px-4 py-2"
                >
                    <span
                        class="h-2.5 w-2.5 rounded-full bg-brand-tertiary"
                        :class="{
                            'animate-pulse': isCameraReady && isModelReady,
                        }"
                    />
                    <span class="text-xs font-black uppercase text-brand-stroke"
                        >Face Registration</span
                    >
                </div>
            </div>
        </div>

        <aside class="flex flex-col gap-6">
            <div
                class="rounded-3xl border-2 border-brand-stroke bg-brand-card p-6 text-brand-stroke shadow-[8px_8px_0px_0px_#001e1d]"
            >
                <div class="mb-5 flex items-center gap-3">
                    <div
                        class="rounded-xl border border-brand-stroke bg-brand-accent p-2"
                    >
                        <ScanFace class="h-5 w-5 text-brand-stroke" />
                    </div>
                    <h1 class="text-2xl font-black">Register Face</h1>
                </div>

                <label
                    class="mb-2 block text-xs font-black uppercase text-brand-bg"
                    for="employee"
                >
                    Employee
                </label>
                <select
                    id="employee"
                    v-model="selectedEmployeeId"
                    class="mb-5 w-full rounded-2xl border-2 border-brand-stroke bg-white px-4 py-3 text-sm font-bold text-brand-stroke"
                >
                    <option
                        v-for="employee in employees"
                        :key="employee.id"
                        :value="employee.employee_id"
                    >
                        {{ employee.last_name }}, {{ employee.first_name }} -
                        {{ employee.employee_id }}
                    </option>
                </select>

                <div
                    class="mb-5 rounded-2xl border border-brand-stroke/20 bg-white p-4"
                >
                    <p class="text-xs font-black uppercase text-brand-bg">
                        Status
                    </p>
                    <p class="mt-1 text-sm font-bold">{{ statusText }}</p>
                </div>

                <div class="mb-5 grid grid-cols-2 gap-3">
                    <div
                        class="rounded-2xl border border-brand-stroke/20 bg-white p-4"
                    >
                        <UsersRound class="mb-2 h-5 w-5 text-brand-bg" />
                        <p class="text-2xl font-black">
                            {{ employees.length }}
                        </p>
                        <p class="text-xs font-bold text-brand-bg">Employees</p>
                    </div>
                    <div
                        class="rounded-2xl border border-brand-stroke/20 bg-white p-4"
                    >
                        <UserRound class="mb-2 h-5 w-5 text-brand-bg" />
                        <p class="text-2xl font-black">{{ faceCount }}</p>
                        <p class="text-xs font-bold text-brand-bg">Faces</p>
                    </div>
                </div>

                <div
                    v-if="selectedEmployee"
                    class="mb-5 flex items-center gap-4 rounded-2xl border-2 border-brand-stroke bg-white p-4"
                >
                    <img
                        v-if="capturedPreview || selectedEmployee.profile_url"
                        :src="capturedPreview || selectedEmployee.profile_url"
                        :alt="employeeName(selectedEmployee)"
                        class="h-14 w-14 rounded-full border-2 border-brand-stroke object-cover"
                    />
                    <UserRound v-else class="h-12 w-12 text-brand-bg" />
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <CheckCircle2
                                v-if="selectedEmployee.profile_url"
                                class="h-5 w-5 shrink-0 text-brand-bg"
                            />
                            <p class="truncate font-black">
                                {{ employeeName(selectedEmployee) }}
                            </p>
                        </div>
                        <p class="truncate text-xs font-bold text-brand-bg">
                            {{
                                selectedEmployee.profile_url
                                    ? 'Registered face exists'
                                    : 'No face registered yet'
                            }}
                        </p>
                    </div>
                </div>

                <div
                    v-if="isReviewingCapture"
                    class="mb-5 rounded-2xl border-2 border-green-700 bg-green-50 p-4 text-green-900"
                >
                    <p class="text-sm font-black">Ready to save?</p>
                    <p class="mt-1 text-xs font-bold">
                        Retake if the photo is blurry, cropped badly, or the
                        employee is not looking straight.
                    </p>
                </div>

                <div
                    v-if="isReviewingCapture"
                    class="mb-4 grid grid-cols-2 gap-3"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-brand-stroke bg-white px-4 py-4 text-sm font-black text-brand-stroke shadow-[4px_4px_0px_0px_#001e1d] disabled:cursor-not-allowed disabled:opacity-60 active:translate-x-1 active:translate-y-1 active:shadow-none"
                        :disabled="isSubmitting"
                        @click="retakeRegistration"
                    >
                        <RotateCcw class="h-5 w-5" />
                        Retake Photo
                    </button>

                    <button
                        type="button"
                        class="flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-brand-stroke bg-brand-accent px-4 py-4 text-sm font-black text-brand-stroke shadow-[4px_4px_0px_0px_#001e1d] disabled:cursor-not-allowed disabled:bg-white disabled:opacity-60 active:translate-x-1 active:translate-y-1 active:shadow-none"
                        :disabled="!canSave"
                        @click="saveRegistration"
                    >
                        <Save class="h-5 w-5" />
                        {{ isSubmitting ? 'Saving...' : 'Save Photo' }}
                    </button>
                </div>

                <Link
                    href="/face"
                    class="block text-center text-xs font-black uppercase text-brand-bg hover:text-brand-stroke"
                >
                    Open recognition screen
                </Link>
            </div>
        </aside>
    </section>
</template>
