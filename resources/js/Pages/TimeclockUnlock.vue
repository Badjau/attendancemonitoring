<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import {
    ArrowLeft,
    KeyRound,
    LoaderCircle,
    LockKeyhole,
    LogIn,
    TriangleAlert,
    UnlockKeyhole,
    UserRound,
} from '@lucide/vue'
import { computed, onMounted, onUnmounted, ref } from 'vue'
import axios from 'axios'

const props = defineProps<{
    isUnlocked: boolean
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
type AdminAction = 'lock' | 'unlock' | 'dashboard'

const videoRef = ref<HTMLVideoElement | null>(null)
const canvasRef = ref<HTMLCanvasElement | null>(null)
const adminUsername = ref('')
const adminPassword = ref('')
const method = ref<UnlockMethod>('admin')
const selectedAdminAction = ref<AdminAction>('unlock')
const isCameraReady = ref(false)
const isSubmitting = ref(false)
const isFingerprintScanning = ref(false)
const isUnlocked = ref(props.isUnlocked)
const errorText = ref('')
const statusText = ref(
    props.isUnlocked
        ? 'Attendance station is unlocked.'
        : 'Attendance station is locked.',
)

let stream: MediaStream | null = null
let autoRfidTimeout: ReturnType<typeof setTimeout> | null = null
let autoRfidBuffer = ''
let autoFingerprintTimeout: ReturnType<typeof setTimeout> | null = null
let isAutoFingerprintScanActive = false
let autoFingerprintScanVersion = 0
let fingerprintPollAbort = false
let fingerprintEvents: EventSource | null = null

const AUTO_FINGERPRINT_RETRY_MS = 700
const AUTO_FINGERPRINT_SCAN_WINDOW_MS = 120000
const AUTO_RFID_SUBMIT_DELAY_MS = 120
const AUTO_RFID_MIN_LENGTH = 3

const hasAdminCredentials = computed(
    () => adminUsername.value.trim() !== '' && adminPassword.value !== '',
)

const csrfToken = (): string =>
    document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') || ''

const setCsrfToken = (token: string) => {
    const meta = document.querySelector('meta[name="csrf-token"]')
    meta?.setAttribute('content', token)
}

const freshCsrfToken = async (): Promise<string> => {
    const response = await fetch('/csrf-token', {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    })

    const payload = await response.json().catch(() => ({}))
    const token = String(payload.token || csrfToken())

    if (token) {
        setCsrfToken(token)
    }

    return token
}

const goBack = () => {
    router.visit('/')
}

const redirectAfterUnlock = (redirect: string) => {
    window.location.href = redirect

    window.setTimeout(() => {
        window.location.replace(redirect)
    }, 150)
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

const submitUnlock = async (
    unlockMethod: UnlockMethod,
    credential: string,
    action: AdminAction = 'unlock',
) => {
    errorText.value = ''

    if (!credential) {
        errorText.value =
            unlockMethod === 'rfid'
                ? 'Scan an RFID card first.'
                : unlockMethod === 'fingerprint'
                  ? 'Scan an authorized fingerprint first.'
                  : 'Enter the admin password first.'
        return
    }

    if (unlockMethod === 'admin' && !adminUsername.value.trim()) {
        errorText.value = 'Enter the admin username first.'
        return
    }

    const auditImage = captureAuditImage()
    if (!auditImage && unlockMethod !== 'admin') {
        errorText.value =
            'Camera is not ready. Allow camera access before continuing.'
        return
    }

    try {
        isSubmitting.value = true
        statusText.value = 'Verifying authorization...'
        const token = await freshCsrfToken()

        const response = await axios.post(
            '/unlock',
            {
                method: unlockMethod,
                username:
                    unlockMethod === 'admin'
                        ? adminUsername.value.trim()
                        : undefined,
                credential,
                action,
                audit_image: auditImage ?? undefined,
                _token: token,
            },
            {
                withCredentials: true,
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        )

        statusText.value = response.data.message ?? 'Timeclock unlocked.'
        const redirect = response.data.redirect ?? '/'
        redirectAfterUnlock(redirect)
    } catch (error: any) {
        errorText.value = error?.response?.data?.message ?? 'Unlock failed.'
        statusText.value = isUnlocked.value
            ? 'Attendance station is unlocked.'
            : 'Attendance station is locked.'
    } finally {
        isSubmitting.value = false
    }
}

const submitAdminAction = async (action: AdminAction) => {
    selectedAdminAction.value = action
    await submitUnlock('admin', adminPassword.value, action)
}

const submitSelectedAdminAction = async () => {
    await submitAdminAction(selectedAdminAction.value)
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

    if (credential.length < AUTO_RFID_MIN_LENGTH || isSubmitting.value) {
        return
    }

    clearAutoFingerprintScan()
    fingerprintPollAbort = true
    isFingerprintScanning.value = false
    method.value = 'rfid'

    await submitUnlock('rfid', credential, isUnlocked.value ? 'lock' : 'unlock')
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

const fingerprintCredential = (status: BridgeStatus): string | null => {
    if (!status.employee_database_id || !status.template_id) return null

    return JSON.stringify({
        employee_id: status.employee_database_id,
        template_id: status.template_id,
        score: status.score ?? null,
    })
}

const bridgeBaseUrl = (): string => props.zktecoBridgeUrl.replace(/\/$/, '')

const closeFingerprintEvents = () => {
    fingerprintEvents?.close()
    fingerprintEvents = null
}

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

const pollFingerprintUnlock = async (
    commandId: string,
    startScan: () => Promise<void>,
    timeoutMs = 30000,
    shouldContinue: (() => boolean) | undefined = undefined,
) => {
    closeFingerprintEvents()

    await new Promise<void>((resolve, reject) => {
        const timeout = setTimeout(() => {
            closeFingerprintEvents()
            reject(new Error('No fingerprint scan was received. Please try again.'))
        }, timeoutMs)

        fingerprintEvents = new EventSource(
            `${bridgeBaseUrl()}/events?command_id=${encodeURIComponent(commandId)}`,
        )

        const finish = async (status: BridgeStatus) => {
            if (fingerprintPollAbort || (shouldContinue && !shouldContinue())) {
                clearTimeout(timeout)
                closeFingerprintEvents()
                resolve()
                return
            }

            if (status.command_id && status.command_id !== commandId) return

            if (status.state === 'matched') {
                const credential = fingerprintCredential(status)

                if (!credential) {
                    clearTimeout(timeout)
                    closeFingerprintEvents()
                    reject(
                        new Error(
                            'Fingerprint match did not include unlock credentials.',
                        ),
                    )
                    return
                }

                clearTimeout(timeout)
                closeFingerprintEvents()
                await submitUnlock(
                    'fingerprint',
                    credential,
                    isUnlocked.value ? 'lock' : 'unlock',
                )
                resolve()
                return
            }

            if (status.state === 'error') {
                clearTimeout(timeout)
                closeFingerprintEvents()
                reject(new Error(status.message || 'Fingerprint scan failed.'))
            }
        }

        fingerprintEvents.onmessage = (event) => {
            finish(JSON.parse(event.data || '{}')).catch(reject)
        }

        ;['waiting_for_scan', 'matched', 'error'].forEach((state) => {
            fingerprintEvents?.addEventListener(state, (event) => {
                finish(JSON.parse(event.data || '{}')).catch(reject)
            })
        })

        startScan().catch((error) => {
            clearTimeout(timeout)
            closeFingerprintEvents()
            reject(error)
        })
    })
}

const runFingerprintUnlock = async (
    scanVersion = autoFingerprintScanVersion,
) => {
    if (isSubmitting.value || isFingerprintScanning.value) {
        return false
    }

    const auditImage = captureAuditImage()
    if (!auditImage) {
        return false
    }

    const commandId = `unlock-${Date.now()}-${Math.random().toString(36).slice(2)}`

    try {
        isFingerprintScanning.value = true
        fingerprintPollAbort = false

        await pollFingerprintUnlock(
            commandId,
            () => postBridgeCommand('commands/unlock', { command_id: commandId }),
            AUTO_FINGERPRINT_SCAN_WINDOW_MS,
            () => scanVersion === autoFingerprintScanVersion,
        )

        return true
    } catch {
        fingerprintPollAbort = true

        return false
    } finally {
        isFingerprintScanning.value = false
    }
}

const clearAutoFingerprintScan = () => {
    if (autoFingerprintTimeout) {
        clearTimeout(autoFingerprintTimeout)
        autoFingerprintTimeout = null
    }

    isAutoFingerprintScanActive = false
    autoFingerprintScanVersion++
}

const scheduleAutoFingerprintScan = (delay = AUTO_FINGERPRINT_RETRY_MS) => {
    if (autoFingerprintTimeout) {
        clearTimeout(autoFingerprintTimeout)
    }

    autoFingerprintTimeout = setTimeout(async () => {
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
            await runFingerprintUnlock(scanVersion)
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
    closeFingerprintEvents()
    stopCamera()
    if (autoRfidTimeout) clearTimeout(autoRfidTimeout)
})
</script>

<template>
    <Head title="Lock / Unlock" />

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
                        <LockKeyhole v-if="!isUnlocked" class="h-4 w-4" />
                        <UnlockKeyhole v-else class="h-4 w-4" />
                        {{ isUnlocked ? 'Timeclock unlocked' : 'Timeclock locked' }}
                    </div>
                    <h1 class="text-3xl font-black leading-tight md:text-4xl">
                        Lock / Unlock station
                    </h1>
                    <p class="text-sm font-semibold text-brand-bg">
                        {{ statusText }}
                    </p>
                </div>

                <form
                    method="post"
                    action="/unlock"
                    autocomplete="off"
                    class="space-y-4"
                    @submit.prevent="submitSelectedAdminAction"
                >
                    <input type="hidden" name="_token" :value="csrfToken()" />
                    <input type="hidden" name="method" value="admin" />
                    <label class="block space-y-2">
                        <span
                            class="text-xs font-black uppercase tracking-widest"
                        >
                            Admin username or email
                        </span>
                        <span class="relative block">
                            <UserRound
                                class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2"
                            />
                            <input
                                v-model="adminUsername"
                                type="text"
                                name="username"
                                autocomplete="username"
                                class="w-full rounded-2xl border-2 border-brand-stroke bg-white py-4 pl-12 pr-4 text-lg font-bold outline-none transition-shadow focus:shadow-[4px_4px_0px_0px_#001e1d]"
                            />
                        </span>
                    </label>

                    <label class="block space-y-2">
                        <span
                            class="text-xs font-black uppercase tracking-widest"
                        >
                            Admin password
                        </span>
                        <span class="relative block">
                            <KeyRound
                                class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2"
                            />
                            <input
                                v-model="adminPassword"
                                type="password"
                                name="credential"
                                autocomplete="current-password"
                                class="w-full rounded-2xl border-2 border-brand-stroke bg-white py-4 pl-12 pr-4 text-lg font-bold outline-none transition-shadow focus:shadow-[4px_4px_0px_0px_#001e1d]"
                            />
                        </span>
                    </label>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <button
                            type="submit"
                            name="action"
                            :value="isUnlocked ? 'lock' : 'unlock'"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-brand-stroke bg-brand-stroke px-4 py-4 text-sm font-black uppercase tracking-widest text-brand-headline shadow-[5px_5px_0px_0px_#abd1c6] transition-all duration-200 hover:-translate-y-1 hover:shadow-[7px_7px_0px_0px_#abd1c6] active:translate-x-1 active:translate-y-1 active:shadow-none disabled:cursor-not-allowed disabled:opacity-70"
                            :disabled="isSubmitting || !hasAdminCredentials"
                            @click="selectedAdminAction = isUnlocked ? 'lock' : 'unlock'"
                        >
                            <LoaderCircle
                                v-if="isSubmitting"
                                class="h-5 w-5 animate-spin"
                            />
                            <LockKeyhole
                                v-else-if="isUnlocked"
                                class="h-5 w-5"
                            />
                            <UnlockKeyhole v-else class="h-5 w-5" />
                            {{ isUnlocked ? 'Lock station' : 'Unlock station' }}
                        </button>

                        <button
                            v-if="hasAdminCredentials"
                            type="submit"
                            name="action"
                            value="dashboard"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-brand-stroke bg-brand-accent px-4 py-4 text-sm font-black uppercase tracking-widest text-brand-stroke shadow-[5px_5px_0px_0px_#001e1d] transition-all duration-200 hover:-translate-y-1 hover:shadow-[7px_7px_0px_0px_#001e1d] active:translate-x-1 active:translate-y-1 active:shadow-none disabled:cursor-not-allowed disabled:opacity-70"
                            :disabled="isSubmitting"
                            @click="selectedAdminAction = 'dashboard'"
                        >
                            <LogIn class="h-5 w-5" />
                            Open admin dashboard
                        </button>
                    </div>
                </form>

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
