<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import {
    IdCard,
    KeyRound,
    LoaderCircle,
    LockKeyhole,
    TriangleAlert,
} from '@lucide/vue'
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import axios from 'axios'

type UnlockMethod = 'keypad' | 'rfid'

const videoRef = ref<HTMLVideoElement | null>(null)
const canvasRef = ref<HTMLCanvasElement | null>(null)
const rfidInput = ref<HTMLInputElement | null>(null)
const password = ref('')
const rfidBuffer = ref('')
const method = ref<UnlockMethod>('keypad')
const isCameraReady = ref(false)
const isSubmitting = ref(false)
const errorText = ref('')
const statusText = ref(
    'Camera audit is required to unlock the attendance system.',
)

let stream: MediaStream | null = null
let rfidTimeout: ReturnType<typeof setTimeout> | null = null

const selectedCredential = computed(() =>
    method.value === 'keypad' ? password.value.trim() : rfidBuffer.value.trim(),
)

const csrfToken = (): string =>
    document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') || ''

const startCamera = async () => {
    if (!navigator.mediaDevices?.getUserMedia) {
        throw new Error('Camera access is not available in this browser.')
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

        videoRef.value.onloadedmetadata = async () => {
            await videoRef.value?.play()
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

const captureAuditImage = (): string | null => {
    if (!videoRef.value || !canvasRef.value || !isCameraReady.value) return null

    const video = videoRef.value
    const canvas = canvasRef.value
    canvas.width = video.videoWidth
    canvas.height = video.videoHeight

    const context = canvas.getContext('2d')
    if (!context) return null

    context.drawImage(video, 0, 0, canvas.width, canvas.height)

    return canvas.toDataURL('image/jpeg', 0.86)
}

const focusRfid = async () => {
    if (method.value !== 'rfid') return

    await nextTick()
    rfidInput.value?.focus()
}

const switchMethod = async (nextMethod: UnlockMethod) => {
    method.value = nextMethod
    errorText.value = ''
    rfidBuffer.value = ''
    password.value = ''
    await focusRfid()
}

const submitUnlock = async () => {
    errorText.value = ''

    if (!selectedCredential.value) {
        errorText.value =
            method.value === 'rfid'
                ? 'Scan an RFID card first.'
                : 'Enter your unlock PIN first.'
        return
    }

    const auditImage = captureAuditImage()
    if (!auditImage) {
        errorText.value =
            'Camera is not ready. Allow camera access before unlocking.'
        return
    }

    try {
        isSubmitting.value = true
        statusText.value = 'Verifying authorization...'

        const response = await axios.post(
            '/unlock',
            {
                method: method.value,
                credential: selectedCredential.value,
                audit_image: auditImage,
                _token: csrfToken(),
            },
            {
                withCredentials: true,
            },
        )

        statusText.value = response.data.message ?? 'Timeclock unlocked.'
        router.visit(response.data.redirect ?? '/')
    } catch (error: any) {
        errorText.value = error?.response?.data?.message ?? 'Unlock failed.'
        statusText.value =
            'Camera audit is required to unlock the attendance system.'
        rfidBuffer.value = ''
        if (rfidInput.value) rfidInput.value.value = ''
        await focusRfid()
    } finally {
        isSubmitting.value = false
    }
}

const onRfidInput = () => {
    const value = rfidInput.value?.value.trim() ?? ''
    if (!value) return

    rfidBuffer.value = value

    if (rfidTimeout) clearTimeout(rfidTimeout)
    rfidTimeout = setTimeout(() => submitUnlock(), 120)
}

const onRfidKeydown = (event: KeyboardEvent) => {
    if (event.key !== 'Enter') return

    event.preventDefault()
    if (rfidTimeout) clearTimeout(rfidTimeout)
    submitUnlock()
}

onMounted(async () => {
    try {
        await startCamera()
        statusText.value = 'Enter PIN or scan RFID to unlock.'
    } catch (error) {
        errorText.value =
            error instanceof Error ? error.message : 'Failed to start.'
    }
})

onUnmounted(() => {
    stopCamera()
    if (rfidTimeout) clearTimeout(rfidTimeout)
})
</script>

<template>
    <Head title="Unlock Timeclock" />

    <main class="min-h-screen bg-brand-bg px-4 py-6 text-brand-stroke md:px-8">
        <section
            class="mx-auto grid min-h-[calc(100vh-3rem)] max-w-xl items-center"
        >
            <video
                ref="videoRef"
                autoplay
                playsinline
                muted
                class="pointer-events-none fixed -left-2499.75 top-0 h-px w-px opacity-0"
                aria-hidden="true"
                tabindex="-1"
            />
            <canvas ref="canvasRef" class="hidden" />

            <div
                class="rounded-4xl border-2 border-brand-stroke bg-brand-card p-6 shadow-[10px_10px_0px_0px_#001e1d] md:p-8"
            >
                <div class="mb-8 space-y-2">
                    <div
                        class="inline-flex items-center gap-2 rounded-full bg-brand-stroke px-4 py-2 text-xs font-black uppercase tracking-widest text-brand-headline"
                    >
                        <LockKeyhole class="h-4 w-4" />
                        Timeclock locked
                    </div>
                    <h1 class="text-3xl font-black leading-tight md:text-4xl">
                        Unlock attendance station
                    </h1>
                    <p class="text-sm font-semibold text-brand-bg">
                        {{ statusText }}
                    </p>
                </div>

                <div class="mb-5 grid grid-cols-2 gap-3">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center gap-2 rounded-2xl border-2 border-brand-stroke px-4 py-3 text-sm font-black transition-all duration-200 hover:-translate-y-0.5 hover:shadow-[4px_4px_0px_0px_#001e1d]"
                        :class="
                            method === 'keypad' ? 'bg-brand-accent' : 'bg-white'
                        "
                        @click="switchMethod('keypad')"
                    >
                        <KeyRound class="h-4 w-4" />
                        PIN
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center gap-2 rounded-2xl border-2 border-brand-stroke px-4 py-3 text-sm font-black transition-all duration-200 hover:-translate-y-0.5 hover:shadow-[4px_4px_0px_0px_#001e1d]"
                        :class="
                            method === 'rfid' ? 'bg-brand-accent' : 'bg-white'
                        "
                        @click="switchMethod('rfid')"
                    >
                        <IdCard class="h-4 w-4" />
                        RFID
                    </button>
                </div>

                <form
                    v-if="method === 'keypad'"
                    class="space-y-4"
                    @submit.prevent="submitUnlock"
                >
                    <input
                        v-model="password"
                        type="password"
                        name="timeclock-unlock-password"
                        autocomplete="new-password"
                        class="w-full rounded-2xl border-2 border-brand-stroke bg-white px-4 py-4 text-lg font-bold outline-none transition-shadow focus:shadow-[4px_4px_0px_0px_#001e1d]"
                        placeholder="Enter unlock PIN"
                    />

                    <button
                        type="submit"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-brand-stroke bg-brand-stroke px-4 py-4 text-sm font-black uppercase tracking-widest text-brand-headline shadow-[5px_5px_0px_0px_#abd1c6] transition-all duration-200 hover:-translate-y-1 hover:shadow-[7px_7px_0px_0px_#abd1c6] active:translate-x-1 active:translate-y-1 active:shadow-none"
                        :disabled="isSubmitting"
                    >
                        <LoaderCircle
                            v-if="isSubmitting"
                            class="h-5 w-5 animate-spin"
                        />
                        <LockKeyhole v-else class="h-5 w-5" />
                        Unlock timeclock
                    </button>
                </form>

                <div v-else class="space-y-4">
                    <input
                        ref="rfidInput"
                        type="text"
                        autocomplete="off"
                        class="w-full rounded-2xl border-2 border-brand-stroke bg-white px-4 py-4 text-lg font-bold outline-none transition-shadow focus:shadow-[4px_4px_0px_0px_#001e1d]"
                        placeholder="Scan RFID card"
                        @input="onRfidInput"
                        @keydown="onRfidKeydown"
                    />

                    <div
                        class="rounded-2xl border-2 border-dashed border-brand-stroke bg-white/60 p-5 text-center"
                    >
                        <IdCard class="mx-auto mb-2 h-8 w-8" />
                        <p class="text-sm font-black uppercase tracking-widest">
                            Waiting for RFID scan
                        </p>
                    </div>
                </div>

                <div
                    v-if="errorText"
                    class="mt-5 flex items-start gap-2 rounded-2xl border-2 border-brand-tertiary bg-white p-4 text-brand-tertiary"
                >
                    <TriangleAlert class="mt-0.5 h-5 w-5 shrink-0" />
                    <p class="text-sm font-bold">{{ errorText }}</p>
                </div>
            </div>
        </section>
    </main>
</template>
