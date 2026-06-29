<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import {
    ArrowLeft,
    Fingerprint,
    IdCard,
    KeyRound,
    LoaderCircle,
    LockKeyhole,
    TriangleAlert,
    UserRound,
} from '@lucide/vue'
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import axios from 'axios'

const props = defineProps<{
    zktecoBridgeUrl: string
}>()

type BridgeStatus = {
    command_id?: string | null
    state?: string | null
    message?: string | null
    employee_database_id?: number | null
    template_id?: number | null
    score?: number | null
}
type UnlockMethod = 'keypad' | 'rfid' | 'fingerprint' | 'admin'

const videoRef = ref<HTMLVideoElement | null>(null)
const canvasRef = ref<HTMLCanvasElement | null>(null)
const rfidInput = ref<HTMLInputElement | null>(null)
const password = ref('')
const adminUsername = ref('')
const adminPassword = ref('')
const rfidBuffer = ref('')
const method = ref<UnlockMethod>('keypad')
const isCameraReady = ref(false)
const isSubmitting = ref(false)
const isFingerprintScanning = ref(false)
const errorText = ref('')
const statusText = ref(
    'Camera audit is required to unlock the attendance system.',
)

let stream: MediaStream | null = null
let rfidTimeout: ReturnType<typeof setTimeout> | null = null
let autoRfidTimeout: ReturnType<typeof setTimeout> | null = null
let autoRfidBuffer = ''
let autoFingerprintTimeout: ReturnType<typeof setTimeout> | null = null
let isAutoFingerprintScanActive = false
let autoFingerprintScanVersion = 0
let fingerprintPollAbort = false

const AUTO_FINGERPRINT_RETRY_MS = 700
const AUTO_FINGERPRINT_SCAN_WINDOW_MS = 120000
const AUTO_RFID_SUBMIT_DELAY_MS = 120
const AUTO_RFID_MIN_LENGTH = 3

const selectedCredential = computed(() =>
    method.value === 'keypad'
        ? password.value.trim()
        : method.value === 'admin'
          ? adminPassword.value.trim()
        : method.value === 'rfid'
          ? rfidBuffer.value.trim()
          : '',
)

const csrfToken = (): string =>
    document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') || ''

const goBack = () => {
    router.visit('/')
}

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
    clearAutoFingerprintScan()
    fingerprintPollAbort = true
    isFingerprintScanning.value = false
    method.value = nextMethod
    errorText.value = ''
    rfidBuffer.value = ''
    password.value = ''
    adminUsername.value = ''
    adminPassword.value = ''
    await focusRfid()
}

const clearAutoFingerprintScan = () => {
    if (autoFingerprintTimeout) {
        clearTimeout(autoFingerprintTimeout)
        autoFingerprintTimeout = null
    }

    isAutoFingerprintScanActive = false
    autoFingerprintScanVersion++
}

const submitUnlock = async (
    unlockMethod: UnlockMethod = method.value,
    credential: string = selectedCredential.value,
) => {
    errorText.value = ''

    if (!credential) {
        errorText.value =
            unlockMethod === 'rfid'
                ? 'Scan an RFID card first.'
                : unlockMethod === 'fingerprint'
                  ? 'Scan an authorized fingerprint first.'
                  : unlockMethod === 'admin'
                    ? 'Enter the admin password first.'
                  : 'Enter your unlock PIN first.'
        return
    }

    if (unlockMethod === 'admin' && !adminUsername.value.trim()) {
        errorText.value = 'Enter the admin username first.'
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
                method: unlockMethod,
                username:
                    unlockMethod === 'admin'
                        ? adminUsername.value.trim()
                        : undefined,
                credential,
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

const isTypingInField = (target: EventTarget | null): boolean => {
    if (!(target instanceof HTMLElement)) return false

    return (
        target instanceof HTMLInputElement ||
        target instanceof HTMLTextAreaElement ||
        target instanceof HTMLSelectElement ||
        target.isContentEditable
    )
}

const submitAutoRfidBuffer = async () => {
    const credential = autoRfidBuffer.trim()
    autoRfidBuffer = ''

    if (
        credential.length < AUTO_RFID_MIN_LENGTH ||
        isSubmitting.value
    ) {
        return
    }

    clearAutoFingerprintScan()
    fingerprintPollAbort = true
    isFingerprintScanning.value = false
    method.value = 'rfid'
    rfidBuffer.value = credential
    password.value = ''
    adminUsername.value = ''
    adminPassword.value = ''

    await submitUnlock('rfid', credential)
}

const onAutoRfidKeydown = (event: KeyboardEvent) => {
    if (isTypingInField(event.target)) return
    if (isSubmitting.value) return
    if (event.ctrlKey || event.altKey || event.metaKey) return

    if (event.key === 'Enter') {
        if (autoRfidTimeout) {
            clearTimeout(autoRfidTimeout)
            autoRfidTimeout = null
        }

        submitAutoRfidBuffer()
        return
    }

    if (event.key.length !== 1) return

    autoRfidBuffer += event.key

    if (autoRfidTimeout) clearTimeout(autoRfidTimeout)
    autoRfidTimeout = setTimeout(
        () => submitAutoRfidBuffer(),
        AUTO_RFID_SUBMIT_DELAY_MS,
    )
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

const fingerprintCredential = (status: BridgeStatus): string | null => {
    if (!status.employee_database_id || !status.template_id) return null

    return JSON.stringify({
        employee_id: status.employee_database_id,
        template_id: status.template_id,
        score: status.score ?? null,
    })
}

const bridgeBaseUrl = (): string => props.zktecoBridgeUrl.replace(/\/$/, '')

const postBridgeCommand = async (
    path: string,
    payload: Record<string, unknown>,
) => {
    const response = await fetch(`${bridgeBaseUrl()}/${path}`, {
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
            bridgePayload.message ||
                'Unable to connect to fingerprint scanner.',
        )
    }
}

const getBridgeStatus = async (): Promise<BridgeStatus> => {
    const response = await fetch(`${bridgeBaseUrl()}/status`, {
        headers: {
            Accept: 'application/json',
        },
    })

    if (!response.ok) {
        throw new Error('Unable to connect to fingerprint scanner.')
    }

    return response.json().catch(() => ({}))
}

const startBridgeUnlockScan = async (
    commandId: string,
    options: { launchBridge?: boolean } = {},
) => {
    const shouldLaunchBridge = options.launchBridge ?? true

    try {
        await postBridgeCommand('unlock', { command_id: commandId })
        return
    } catch (error) {
        if (!shouldLaunchBridge) {
            throw error
        }

        window.location.href = `zkteco-bridge://unlock?payload=${encodeURIComponent(
            JSON.stringify({ command_id: commandId }),
        )}`
    }

    let lastError: any = null
    const startedAt = Date.now()

    while (Date.now() - startedAt < 12000) {
        await new Promise((resolve) => setTimeout(resolve, 700))

        try {
            await postBridgeCommand('unlock', { command_id: commandId })
            return
        } catch (error) {
            lastError = error
        }
    }

    throw lastError || new Error('Unable to connect to fingerprint scanner.')
}

const pollFingerprintUnlock = async (
    commandId: string,
    timeoutMs = 30000,
    shouldContinue: (() => boolean) | undefined = undefined,
) => {
    const startedAt = Date.now()

    while (!fingerprintPollAbort && Date.now() - startedAt < timeoutMs) {
        if (shouldContinue && !shouldContinue()) return

        const status = await getBridgeStatus()

        if (shouldContinue && !shouldContinue()) return

        if (status.command_id === commandId && status.message) {
            statusText.value = status.message
        }

        if (status.command_id === commandId && status.state === 'matched') {
            const credential = fingerprintCredential(status)

            if (!credential) {
                throw new Error(
                    'Fingerprint match did not include unlock credentials.',
                )
            }

            await submitUnlock('fingerprint', credential)
            return
        }

        if (status.command_id === commandId && status.state === 'error') {
            throw new Error(status.message || 'Fingerprint scan failed.')
        }

        await new Promise((resolve) => setTimeout(resolve, 700))
    }

    throw new Error('No fingerprint scan was received. Please try again.')
}

const runFingerprintUnlock = async (
    automatic = false,
    scanVersion = autoFingerprintScanVersion,
) => {
    if (
        isSubmitting.value ||
        isFingerprintScanning.value
    ) {
        return false
    }

    if (!automatic) {
        clearAutoFingerprintScan()
    }

    errorText.value = ''
    fingerprintPollAbort = true
    method.value = 'fingerprint'
    rfidBuffer.value = ''
    password.value = ''
    adminUsername.value = ''
    adminPassword.value = ''

    const auditImage = captureAuditImage()
    if (!auditImage) {
        if (!automatic) {
            errorText.value =
                'Camera is not ready. Allow camera access before unlocking.'
        }

        return false
    }

    const commandId = `unlock-${Date.now()}-${Math.random().toString(36).slice(2)}`

    try {
        isFingerprintScanning.value = true
        fingerprintPollAbort = false
        statusText.value = 'Connecting to fingerprint scanner...'

        await startBridgeUnlockScan(commandId, {
            launchBridge: !automatic,
        })

        statusText.value = 'Scan an authorized fingerprint to unlock.'
        await pollFingerprintUnlock(
            commandId,
            automatic ? AUTO_FINGERPRINT_SCAN_WINDOW_MS : 30000,
            automatic
                ? () => scanVersion === autoFingerprintScanVersion
                : undefined,
        )

        return true
    } catch (error: any) {
        fingerprintPollAbort = true
        if (!automatic) {
            errorText.value =
                error?.response?.data?.message ||
                error?.message ||
                'Unable to scan fingerprint.'
        }
        statusText.value =
            'Enter PIN, scan RFID, or scan fingerprint to unlock.'

        return false
    } finally {
        isFingerprintScanning.value = false
    }
}

const startFingerprintUnlock = async () => {
    await runFingerprintUnlock(false)

    scheduleAutoFingerprintScan()
}

const scheduleAutoFingerprintScan = (delay = AUTO_FINGERPRINT_RETRY_MS) => {
    if (autoFingerprintTimeout) {
        clearTimeout(autoFingerprintTimeout)
    }

    autoFingerprintTimeout = setTimeout(async () => {
        if (method.value !== 'fingerprint') {
            return
        }

        if (
            isAutoFingerprintScanActive ||
            isSubmitting.value ||
            isFingerprintScanning.value ||
            !isCameraReady.value
        ) {
            scheduleAutoFingerprintScan()
            return
        }

        isAutoFingerprintScanActive = true
        const scanVersion = ++autoFingerprintScanVersion

        try {
            await runFingerprintUnlock(true, scanVersion)
        } finally {
            if (scanVersion === autoFingerprintScanVersion) {
                isAutoFingerprintScanActive = false
                scheduleAutoFingerprintScan()
            }
        }
    }, delay)
}

onMounted(async () => {
    window.addEventListener('keydown', onAutoRfidKeydown)

    try {
        await startCamera()
        method.value = 'fingerprint'
        statusText.value = 'Scan an authorized fingerprint to unlock.'
        scheduleAutoFingerprintScan(1000)
    } catch (error) {
        errorText.value =
            error instanceof Error ? error.message : 'Failed to start.'
    }
})

onUnmounted(() => {
    window.removeEventListener('keydown', onAutoRfidKeydown)
    clearAutoFingerprintScan()
    fingerprintPollAbort = true
    stopCamera()
    if (rfidTimeout) clearTimeout(rfidTimeout)
    if (autoRfidTimeout) clearTimeout(autoRfidTimeout)
})
</script>

<template>
    <Head title="Unlock Timeclock" />

    <main class="min-h-screen bg-brand-bg px-4 py-6 text-brand-stroke md:px-8">
        <button
            type="button"
            class="fixed left-3 top-3 z-20 inline-flex items-center justify-center gap-2 rounded-2xl border-2 border-brand-stroke bg-brand-card px-4 py-3 text-xs font-black uppercase tracking-widest text-brand-stroke shadow-[4px_4px_0px_0px_#001e1d] transition-all duration-200 hover:-translate-y-0.5 hover:shadow-[6px_6px_0px_0px_#001e1d] active:translate-x-1 active:translate-y-1 active:shadow-none md:left-5 md:top-5"
            @click="goBack"
        >
            <ArrowLeft class="h-4 w-4" />
            Back
        </button>

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

                <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
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
                    <button
                        type="button"
                        class="inline-flex items-center justify-center gap-2 rounded-2xl border-2 border-brand-stroke px-4 py-3 text-sm font-black transition-all duration-200 hover:-translate-y-0.5 hover:shadow-[4px_4px_0px_0px_#001e1d]"
                        :class="
                            method === 'fingerprint'
                                ? 'bg-brand-accent'
                                : 'bg-white'
                        "
                        @click="switchMethod('fingerprint')"
                    >
                        <Fingerprint class="h-4 w-4" />
                        Finger
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center gap-2 rounded-2xl border-2 border-brand-stroke px-4 py-3 text-sm font-black transition-all duration-200 hover:-translate-y-0.5 hover:shadow-[4px_4px_0px_0px_#001e1d]"
                        :class="
                            method === 'admin' ? 'bg-brand-accent' : 'bg-white'
                        "
                        @click="switchMethod('admin')"
                    >
                        <UserRound class="h-4 w-4" />
                        Admin
                    </button>
                </div>

                <form
                    v-if="method === 'keypad'"
                    class="space-y-4"
                    @submit.prevent="submitUnlock()"
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

                <div v-else-if="method === 'rfid'" class="space-y-4">
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

                <form
                    v-else-if="method === 'admin'"
                    class="space-y-4"
                    @submit.prevent="submitUnlock()"
                >
                    <input
                        v-model="adminUsername"
                        type="text"
                        name="admin-username"
                        autocomplete="username"
                        class="w-full rounded-2xl border-2 border-brand-stroke bg-white px-4 py-4 text-lg font-bold outline-none transition-shadow focus:shadow-[4px_4px_0px_0px_#001e1d]"
                        placeholder="Admin username or email"
                    />

                    <input
                        v-model="adminPassword"
                        type="password"
                        name="admin-password"
                        autocomplete="current-password"
                        class="w-full rounded-2xl border-2 border-brand-stroke bg-white px-4 py-4 text-lg font-bold outline-none transition-shadow focus:shadow-[4px_4px_0px_0px_#001e1d]"
                        placeholder="Admin password"
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
                        <UserRound v-else class="h-5 w-5" />
                        Open admin dashboard
                    </button>
                </form>

                <div v-else class="space-y-4">
                    <button
                        type="button"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-brand-stroke bg-brand-stroke px-4 py-4 text-sm font-black uppercase tracking-widest text-brand-headline shadow-[5px_5px_0px_0px_#abd1c6] transition-all duration-200 hover:-translate-y-1 hover:shadow-[7px_7px_0px_0px_#abd1c6] active:translate-x-1 active:translate-y-1 active:shadow-none disabled:cursor-not-allowed disabled:opacity-70"
                        :disabled="isSubmitting || isFingerprintScanning"
                        @click="startFingerprintUnlock"
                    >
                        <LoaderCircle
                            v-if="isFingerprintScanning || isSubmitting"
                            class="h-5 w-5 animate-spin"
                        />
                        <Fingerprint v-else class="h-5 w-5" />
                        Scan fingerprint
                    </button>

                    <div
                        class="rounded-2xl border-2 border-dashed border-brand-stroke bg-white/60 p-5 text-center"
                    >
                        <Fingerprint class="mx-auto mb-2 h-8 w-8" />
                        <p class="text-sm font-black uppercase tracking-widest">
                            {{
                                isFingerprintScanning
                                    ? 'Waiting for fingerprint scan'
                                    : 'Ready for manual fingerprint unlock'
                            }}
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
