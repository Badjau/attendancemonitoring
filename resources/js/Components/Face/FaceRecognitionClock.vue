<script setup lang="ts">
import * as faceapi from 'face-api.js'
import {
    Camera,
    CheckCircle2,
    Clock,
    LoaderCircle,
    LogIn,
    LogOut,
    MapPin,
    ScanFace,
    TriangleAlert,
    UserRound,
    UsersRound,
} from '@lucide/vue'
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { useToast } from 'primevue'
import { useGeolocator } from '@/Composables/useGeolocator.js'
import { mapFaceBoxToObjectCover } from '@/Utils/faceOverlay.js'

type AttendanceAction = 'time-in' | 'time-out'

type FaceEmployee = {
    id: number
    employee_id: string
    first_name: string
    last_name: string
    position: string
    profile_url: string
}

type AttendanceGreeting = {
    first_name: string
    is_birthday: boolean
    attendance_type: AttendanceAction
}

const props = defineProps<{
    employees: FaceEmployee[]
}>()

const page = usePage()
const toast = useToast()
const {
    coords,
    error: locationError,
    loading: locationLoading,
    accuracyWarning,
    usingCachedLocation,
    getLocation,
} = useGeolocator()

const videoRef = ref<HTMLVideoElement | null>(null)
const overlayRef = ref<HTMLCanvasElement | null>(null)
const captureCanvasRef = ref<HTMLCanvasElement | null>(null)

const attendanceType = ref<AttendanceAction>('time-in')
const currentTime = ref('')
const currentDate = ref('')
const statusText = ref('Loading face models...')
const isCameraReady = ref(false)
const isModelReady = ref(false)
const isTraining = ref(false)
const isSubmitting = ref(false)
const matchedEmployee = ref<FaceEmployee | null>(null)
const recognitionCount = ref(0)
const enrolledCount = ref(0)

let stream: MediaStream | null = null
let clockInterval: ReturnType<typeof setInterval> | null = null
let recognitionInterval: ReturnType<typeof setInterval> | null = null
let faceMatcher: faceapi.FaceMatcher | null = null

const modelPath = '/models/face-api'
const matchThreshold = 0.52
const submitCooldownMs = 4500
let lastSubmitAt = 0

const detectorOptions = computed(
    () =>
        new faceapi.TinyFaceDetectorOptions({
            inputSize: 416,
            scoreThreshold: 0.5,
        }),
)

const employeesWithPhotos = computed(() =>
    props.employees.filter((employee) => employee.profile_url),
)
const selectedActionLabel = computed(() =>
    attendanceType.value === 'time-in' ? 'Time In' : 'Time Out',
)
const isLocationReady = computed(
    () =>
        Boolean(coords.value) &&
        Number.isFinite(coords.value.latitude) &&
        Number.isFinite(coords.value.longitude) &&
        !locationError.value,
)

const employeeName = (employee: FaceEmployee) =>
    `${employee.first_name} ${employee.last_name}`.trim()

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

const loadModels = async () => {
    await Promise.all([
        faceapi.nets.tinyFaceDetector.loadFromUri(modelPath),
        faceapi.nets.faceLandmark68Net.loadFromUri(modelPath),
        faceapi.nets.faceRecognitionNet.loadFromUri(modelPath),
    ])

    isModelReady.value = true
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

const buildFaceMatcher = async () => {
    isTraining.value = true
    statusText.value = 'Reading employee profile faces...'

    const labeledDescriptors: faceapi.LabeledFaceDescriptors[] = []

    for (const employee of employeesWithPhotos.value) {
        try {
            const image = await faceapi.fetchImage(employee.profile_url)
            const detection = await faceapi
                .detectSingleFace(image, detectorOptions.value)
                .withFaceLandmarks()
                .withFaceDescriptor()

            if (!detection) continue

            labeledDescriptors.push(
                new faceapi.LabeledFaceDescriptors(employee.employee_id, [
                    detection.descriptor,
                ]),
            )
        } catch (error) {
            console.error(`Unable to enroll ${employee.employee_id}`, error)
        }
    }

    enrolledCount.value = labeledDescriptors.length
    faceMatcher = labeledDescriptors.length
        ? new faceapi.FaceMatcher(labeledDescriptors, matchThreshold)
        : null

    isTraining.value = false
    statusText.value = faceMatcher
        ? 'Look straight at the camera.'
        : 'No employee profile faces were detected.'
}

const stopCamera = () => {
    stream?.getTracks().forEach((track) => track.stop())
    stream = null
    isCameraReady.value = false
}

const captureAttendanceImage = (): Blob | null => {
    if (!videoRef.value || !captureCanvasRef.value) return null

    const video = videoRef.value
    const canvas = captureCanvasRef.value
    canvas.width = video.videoWidth
    canvas.height = video.videoHeight

    const context = canvas.getContext('2d')
    if (!context) return null

    context.drawImage(video, 0, 0, canvas.width, canvas.height)

    const dataUrl = canvas.toDataURL('image/jpeg', 0.86)
    const byteString = atob(dataUrl.split(',')[1])
    const buffer = new Uint8Array(byteString.length)

    for (let i = 0; i < byteString.length; i++) {
        buffer[i] = byteString.charCodeAt(i)
    }

    return new Blob([buffer], { type: 'image/jpeg' })
}

const announceAttendanceGreeting = (greeting?: AttendanceGreeting) => {
    if (!greeting?.first_name) return

    window.dispatchEvent(
        new CustomEvent('attendance:greeting', {
            detail: greeting,
        }),
    )
}

const submitAttendance = (employee: FaceEmployee) => {
    const now = Date.now()
    if (isSubmitting.value || now - lastSubmitAt < submitCooldownMs) return

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

    const image = captureAttendanceImage()
    if (!image) {
        toast.add({
            severity: 'error',
            summary: 'Camera',
            detail: 'Camera image is not ready.',
            life: 5000,
        })
        return
    }

    lastSubmitAt = now
    isSubmitting.value = true
    statusText.value = `Recording ${selectedActionLabel.value.toLowerCase()} for ${employeeName(employee)}...`

    const csrfToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content')
    const payload = {
        rfid: employee.employee_id,
        attendance_method: 'face',
        attendance_type: attendanceType.value,
        latitude: coords.value.latitude,
        longitude: coords.value.longitude,
        'attendance-image': new File(
            [image],
            `face_attendance_${Date.now()}.jpg`,
            {
                type: 'image/jpeg',
            },
        ),
        ...(csrfToken ? { _token: csrfToken } : {}),
    }

    router.post('/attendance/record-time-in', payload, {
        forceFormData: true,
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            const flash = page.props.flash as any

            if (flash?.error) {
                toast.add({
                    severity: 'error',
                    summary: 'Attendance',
                    detail: flash.error,
                    life: 5000,
                })
                statusText.value = 'Look straight at the camera.'
                return
            }

            toast.add({
                severity: 'success',
                summary: 'Attendance',
                detail: flash?.success ?? 'Attendance recorded successfully.',
                life: 5000,
            })

            announceAttendanceGreeting(flash?.greeting)
            statusText.value = `${selectedActionLabel.value} recorded for ${employeeName(employee)}.`
        },
        onError: (errors) => {
            toast.add({
                severity: 'error',
                summary: 'Attendance',
                detail:
                    Object.values(errors)[0] ?? 'Failed to record attendance.',
                life: 5000,
            })
            statusText.value = 'Look straight at the camera.'
        },
        onFinish: () => {
            isSubmitting.value = false
        },
    })
}

const recognizeFace = async () => {
    if (
        !videoRef.value ||
        !overlayRef.value ||
        !faceMatcher ||
        isSubmitting.value
    )
        return
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
        .detectAllFaces(video, detectorOptions.value)
        .withFaceLandmarks()
        .withFaceDescriptors()

    recognitionCount.value = detections.length
    const context = canvas.getContext('2d')
    context?.clearRect(0, 0, canvas.width, canvas.height)

    if (!detections.length) {
        matchedEmployee.value = null
        statusText.value = 'No face detected.'
        return
    }

    let bestEmployee: FaceEmployee | null = null

    detections.forEach((detection, index) => {
        const result = faceMatcher?.findBestMatch(detections[index].descriptor)
        const employee =
            props.employees.find(
                (item) => item.employee_id === result?.label,
            ) ?? null
        const label = employee ? employeeName(employee) : 'Unknown'

        const drawBox = new faceapi.draw.DrawBox(
            mapFaceBoxToObjectCover(detection.detection.box, video),
            {
                label: result
                    ? `${label} (${result.distance.toFixed(2)})`
                    : label,
                boxColor: employee ? '#f9bc60' : '#e16162',
                lineWidth: 3,
            },
        )

        drawBox.draw(canvas)

        if (employee && !bestEmployee) {
            bestEmployee = employee
        }
    })

    matchedEmployee.value = bestEmployee

    if (!bestEmployee) {
        statusText.value = 'Face not recognized.'
        return
    }

    statusText.value = `Recognized ${employeeName(bestEmployee)}.`
    submitAttendance(bestEmployee)
}

const startRecognitionLoop = () => {
    if (recognitionInterval) clearInterval(recognitionInterval)

    recognitionInterval = setInterval(() => {
        recognizeFace().catch((error) => {
            console.error('Face recognition failed.', error)
            statusText.value =
                'Recognition failed. Check profile photos and lighting.'
        })
    }, 900)
}

onMounted(async () => {
    updateTime()
    clockInterval = setInterval(updateTime, 1000)
    getLocation().catch(() => null)

    try {
        await loadModels()
        await nextTick()
        await startCamera()
        await buildFaceMatcher()

        if (faceMatcher) startRecognitionLoop()
    } catch (error) {
        console.error(error)
        statusText.value =
            error instanceof Error
                ? error.message
                : 'Unable to start facial recognition.'
        toast.add({
            severity: 'error',
            summary: 'Facial Recognition',
            detail: statusText.value,
            life: 7000,
        })
    }
})

onUnmounted(() => {
    stopCamera()

    if (clockInterval) clearInterval(clockInterval)
    if (recognitionInterval) clearInterval(recognitionInterval)
})
</script>

<template>
    <section
        class="mx-auto grid max-w-7xl gap-6 lg:grid-cols-[minmax(0,1fr)_360px]"
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
                        'opacity-100': isCameraReady,
                        'opacity-0': !isCameraReady,
                    }"
                />

                <canvas
                    ref="overlayRef"
                    class="absolute inset-0 h-full w-full"
                />
                <canvas ref="captureCanvasRef" class="hidden" />

                <div
                    class="absolute left-4 top-4 flex items-center gap-2 rounded-full border border-brand-stroke bg-brand-card px-4 py-2"
                >
                    <span
                        class="h-2.5 w-2.5 rounded-full bg-brand-tertiary"
                        :class="{
                            'animate-pulse': isCameraReady && isModelReady,
                        }"
                    />
                    <span
                        class="text-xs font-black uppercase text-brand-stroke"
                    >
                        {{ isSubmitting ? 'Recording' : 'Live Recognition' }}
                    </span>
                </div>

                <div
                    class="absolute right-4 top-4 flex items-center gap-2 rounded-full border border-brand-stroke bg-brand-card px-4 py-2"
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
                    <span
                        class="text-xs font-black uppercase text-brand-stroke"
                    >
                        <template v-if="locationLoading"
                            >Getting location...</template
                        >
                        <template v-else-if="locationError"
                            >Location unavailable</template
                        >
                        <template v-else-if="usingCachedLocation"
                            >Using last known GPS location.</template
                        >
                        <template v-else-if="accuracyWarning"
                            >Low GPS accuracy. Please move to an open
                            area.</template
                        >
                        <template v-else-if="isLocationReady"
                            >Location ready</template
                        >
                        <template v-else>Location pending</template>
                    </span>
                </div>
            </div>
        </div>

        <aside class="flex flex-col gap-6">
            <div
                class="rounded-3xl border-2 border-brand-stroke bg-brand-card p-6 shadow-[8px_8px_0px_0px_#001e1d]"
            >
                <div class="mb-5 flex items-center gap-3">
                    <div
                        class="rounded-xl border border-brand-stroke bg-brand-accent p-2"
                    >
                        <Clock class="h-5 w-5 text-brand-stroke" />
                    </div>
                    <div>
                        <p class="text-xs font-black uppercase text-brand-bg">
                            {{ currentDate }}
                        </p>
                        <h1
                            class="text-3xl font-black tabular-nums text-brand-stroke"
                        >
                            {{ currentTime }}
                        </h1>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <button
                        type="button"
                        class="flex items-center justify-center gap-2 rounded-2xl border-2 border-brand-stroke px-4 py-4 text-sm font-black text-brand-stroke shadow-[4px_4px_0px_0px_#001e1d] active:translate-x-1 active:translate-y-1 active:shadow-none"
                        :class="
                            attendanceType === 'time-in'
                                ? 'bg-brand-accent'
                                : 'bg-white'
                        "
                        @click="attendanceType = 'time-in'"
                    >
                        <LogIn class="h-5 w-5" />
                        Time In
                    </button>

                    <button
                        type="button"
                        class="flex items-center justify-center gap-2 rounded-2xl border-2 border-brand-stroke px-4 py-4 text-sm font-black text-brand-stroke shadow-[4px_4px_0px_0px_#001e1d] active:translate-x-1 active:translate-y-1 active:shadow-none"
                        :class="
                            attendanceType === 'time-out'
                                ? 'bg-brand-tertiary text-brand-headline'
                                : 'bg-white'
                        "
                        @click="attendanceType = 'time-out'"
                    >
                        <LogOut class="h-5 w-5" />
                        Time Out
                    </button>
                </div>
            </div>

            <div
                class="rounded-3xl border-2 border-brand-stroke bg-brand-card p-6 text-brand-stroke shadow-[8px_8px_0px_0px_#001e1d]"
            >
                <div class="mb-5 flex items-center gap-3">
                    <div
                        class="rounded-xl border border-brand-stroke bg-brand-paragraph p-2"
                    >
                        <ScanFace class="h-5 w-5 text-brand-bg" />
                    </div>
                    <h2 class="text-xl font-black">Recognition</h2>
                </div>

                <div class="space-y-4">
                    <div
                        class="rounded-2xl border border-brand-stroke/20 bg-white p-4"
                    >
                        <p class="text-xs font-black uppercase text-brand-bg">
                            Status
                        </p>
                        <p class="mt-1 text-sm font-bold">{{ statusText }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div
                            class="rounded-2xl border border-brand-stroke/20 bg-white p-4"
                        >
                            <UsersRound class="mb-2 h-5 w-5 text-brand-bg" />
                            <p class="text-2xl font-black">
                                {{ enrolledCount }}
                            </p>
                            <p class="text-xs font-bold text-brand-bg">
                                Enrolled
                            </p>
                        </div>
                        <div
                            class="rounded-2xl border border-brand-stroke/20 bg-white p-4"
                        >
                            <UserRound class="mb-2 h-5 w-5 text-brand-bg" />
                            <p class="text-2xl font-black">
                                {{ recognitionCount }}
                            </p>
                            <p class="text-xs font-bold text-brand-bg">
                                Detected
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="matchedEmployee"
                        class="flex items-center gap-4 rounded-2xl border-2 border-brand-stroke bg-brand-accent p-4"
                    >
                        <img
                            :src="matchedEmployee.profile_url"
                            :alt="employeeName(matchedEmployee)"
                            class="h-14 w-14 rounded-full border-2 border-brand-stroke object-cover"
                        />
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <CheckCircle2 class="h-5 w-5 shrink-0" />
                                <p class="truncate font-black">
                                    {{ employeeName(matchedEmployee) }}
                                </p>
                            </div>
                            <p class="truncate text-xs font-bold">
                                {{ matchedEmployee.employee_id }} ·
                                {{ matchedEmployee.position }}
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="isTraining"
                        class="rounded-2xl border border-brand-stroke bg-brand-paragraph/40 p-4"
                    >
                        <p class="text-sm font-bold">
                            Preparing {{ employeesWithPhotos.length }} profile
                            photo{{
                                employeesWithPhotos.length === 1 ? '' : 's'
                            }}...
                        </p>
                    </div>
                </div>
            </div>
        </aside>
    </section>
</template>
