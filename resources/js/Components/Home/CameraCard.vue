<script setup lang="ts">
import {Camera, LogIn, LogOut} from "@lucide/vue";
import {nextTick, onMounted, onUnmounted, ref, watch} from "vue";
import {router, usePage} from '@inertiajs/vue3'
import {useToast} from "primevue";

const page = usePage();
const toast = useToast();

const attendanceType = ref('')
const videoRef = ref<HTMLVideoElement | null>(null)
const canvasRef = ref<HTMLCanvasElement | null>(null)
const isLoading = ref(true)
const isError = ref(false)
const isVideoReady = ref(false)

const currentTime = ref("")
const currentDate = ref("")

const hasTimedIn = ref(false)
const showTimeInInputField = ref(false);

const rfidInput = ref(null)
const empIdInput = ref(null)
const rfidBuffer = ref('')

let stream: MediaStream | null = null
let interval: ReturnType<typeof setInterval>
let focusInterval: ReturnType<typeof setInterval>
let rfidTimeout: any = null

const lastScannedTime = ref(0)
const SCAN_COOLDOWN_MS = 1000

const empIdBuffer = ref('')
let empIdTimeout: any = null

const initializeCamera = async () => {
    try {
        if (!navigator.mediaDevices?.getUserMedia) {
            throw new Error("getUserMedia is not available in this browser/context.")
        }

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
        videoRef.value.onloadedmetadata = () => {
            videoRef.value?.play()
            isLoading.value = false
            isVideoReady.value = true
        }
    } catch (error) {
        console.error("Camera error:", error)
        isLoading.value = false
        isError.value = true
    }
}

const stopCamera = (): any => {
    stream?.getTracks().forEach(track => track.stop())
    stream = null
    isVideoReady.value = false
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

const handleTimeAction = (actionName: "time-in" | "time-out") => {
    const isTimeIn = actionName === "time-in"
    attendanceType.value = actionName

    if (isTimeIn && !hasTimedIn.value) {
        showTimeInInputField.value = true
    }
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
    // Always ensure RFID input has focus unless empIdInput is active
    if (document.activeElement === empIdInput.value) return

    try {
        if (rfidInput.value && document.activeElement !== rfidInput.value) {
            rfidInput.value.focus()
        }
    } catch (e) {
        // Silently fail
    }
}

const forceRFIDFocus = () => {
    try {
        rfidInput.value?.focus?.()
    } catch (e) {
        // Silently fail
    }
}

const onRFIDInput = () => {
    const data = rfidInput.value?.value.trim()

    if (data && data.length > 0) {
        rfidBuffer.value = data

        if (rfidTimeout) clearTimeout(rfidTimeout)

        rfidTimeout = setTimeout(() => {
            processRFIDData(rfidBuffer.value)
            rfidBuffer.value = ''
            if (rfidInput.value) {
                rfidInput.value.value = ''
            }

            // Ensure focus returns to RFID input after processing
            setTimeout(() => {
                ensureRFIDFocus()
            }, 50)
        }, 100)
    }
}

const onRFIDKeydown = (e: any) => {
    if (e.key === 'Enter') {
        e.preventDefault()

        if (rfidTimeout) clearTimeout(rfidTimeout)

        processRFIDData(rfidBuffer.value || rfidInput.value?.value)
        rfidBuffer.value = ''
        if (rfidInput.value) {
            rfidInput.value.value = ''
        }
        // Ensure focus returns to RFID input after processing
        setTimeout(() => {
            ensureRFIDFocus()
        }, 50)
    }
}

// EmpID field can steal focus (watch(showTimeInInputField) focuses it), which means
// RFID scanner input ends up here. Treat it the same as RFID input: debounce/Enter
// triggers processing, and ensure each scan replaces (not concatenates) the previous.
const onEmpIdFocus = (e: FocusEvent) => {
    // Select-all so the next scan overwrites the existing value instead of appending.
    const el = e.target as HTMLInputElement | null
    el?.select?.()
}

const onEmpIdInput = () => {
    const data = empIdInput.value?.value?.trim()
    if (!data) return

    empIdBuffer.value = data
    if (empIdTimeout) clearTimeout(empIdTimeout)

    empIdTimeout = setTimeout(() => {
        processRFIDData(empIdBuffer.value)
        empIdBuffer.value = ''
        if (empIdInput.value) empIdInput.value.value = ''
        // After a scan is processed, return focus to the hidden RFID input.
        setTimeout(() => forceRFIDFocus(), 50)
    }, 100)
}

const onEmpIdKeydown = (e: any) => {
    if (e.key !== 'Enter') return
    e.preventDefault()

    if (empIdTimeout) clearTimeout(empIdTimeout)
    processRFIDData(empIdBuffer.value || empIdInput.value?.value)
    empIdBuffer.value = ''
    if (empIdInput.value) empIdInput.value.value = ''
    setTimeout(() => forceRFIDFocus(), 50)
}

const processRFIDData = async (data: any) => {
    if (!data || data.trim().length === 0) {
        console.log('No RFID data provided:', data);
        return;
    }

    const now = Date.now()
    if (now - lastScannedTime.value < SCAN_COOLDOWN_MS) {
        console.log('Scan cooldown active, ignoring scan');
        return;
    }
    lastScannedTime.value = now

    console.log('Processing RFID scan:', data);

    // Auto-capture image
    const image = captureImage()

    if (!image) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'Camera not ready. Please allow camera access.',
            life: 5000,
        })

        console.log('Camera not ready. Please allow camera access.')
        return
    }

    // Submit attendance immediately without waiting for user confirmation
    try {
        console.log('Submitting attendance...');
        // Send RFID + captured image to your backend
        await submitAttendance(data.trim(), image)
    } catch (e) {
        console.error('Error submitting attendance:', e);
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'Failed to record attendance.',
            life: 5000,
        })
    }
}

const submitAttendance = (rfid: string, image: string): Promise<void> => {
    return new Promise((resolve, reject) => {
        const formData = new FormData()

        formData.append('rfid', rfid)
        formData.append('attendance_type', attendanceType.value)

        const blob = base64ToBlob(image, 'image/jpeg')
        formData.append('attendance-image', blob, `attendance_${Date.now()}.jpg`)

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
                    showTimeInInputField.value = false
                    isLoading.value = false

                    reject(new Error(flash.error))
                    return
                }

                toast.add({
                    severity: 'success',
                    summary: 'Success',
                    detail: flash?.success ?? 'Attendance recorded successfully.',
                    life: 5000,
                })

                showTimeInInputField.value = false

                resolve()
            },
            onError: (errors) => {
                toast.add({
                    severity: 'error',
                    summary: 'Error',
                    detail: Object.values(errors)[0] ?? 'Failed to record attendance.',
                    life: 5000,
                })
                showTimeInInputField.value = false
                isLoading.value = false

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

watch(showTimeInInputField, async (val) => {
    if (val) {
        await nextTick()
        empIdInput.value?.focus()
    }
})

const onDocumentClick = (e: MouseEvent) => {
    if (e.target === empIdInput.value) return
    focusRFID()
}

onMounted(async () => {
    updateTime()
    interval = setInterval(updateTime, 1000)

    await initializeCamera()

    ensureRFIDFocus()

    // Maintain RFID focus - keep trying to focus it every 300ms
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
    if (empIdTimeout) clearTimeout(empIdTimeout)
})
</script>

<template>
    <div
        class="bg-brand-card rounded-[2.5rem] p-4 shadow-[12px_12px_0px_0px_#001e1d] border-2 border-brand-stroke grow relative overflow-hidden flex flex-col"
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
            class="w-full h-full rounded-4xl bg-brand-stroke overflow-hidden relative flex items-center justify-center grow"
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
                class="w-full rounded-2xl border-2 border-brand-stroke"
                :class="{ loaded: isVideoReady }"
            ></video>

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
    </div>


    <!-- Clock & Birthdays -->
    <div class="flex flex-col gap-8">
        <!-- Clock Card -->
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
                <div v-if="isLoading" class="mt-5">
                    <p class="text-brand-bg font-bold tracking-wider uppercase text-xs">
                        Processing, please wait...
                    </p>
                </div>
                <div class="flex flex-col justify-center gap-3" v-else>
                    <div class="w-full">
                        <!-- Hidden RFID capture input -->
                        <input
                            ref="rfidInput"
                            type="text"
                            autocomplete="off"
                            class="absolute -top-96"
                            style="opacity: 0; pointer-events: none;"
                            @input="onRFIDInput"
                            @keydown="onRFIDKeydown"
                        />

                        <input
                            v-if="showTimeInInputField"
                            ref="empIdInput"
                            type="text"
                            placeholder="Employee ID"
                            class="text-brand-stroke border-2 border-brand-stroke rounded-xl py-3 px-3 text-sm w-full mt-4"
                            @focus="onEmpIdFocus"
                            @input="onEmpIdInput"
                            @keydown="onEmpIdKeydown"
                        />
                    </div>

                    <p
                        v-if="showTimeInInputField"
                        class="text-brand-stroke text-sm font-bold italic"
                    >
                        Look straight at the camera to record your attendance.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>

</style>
