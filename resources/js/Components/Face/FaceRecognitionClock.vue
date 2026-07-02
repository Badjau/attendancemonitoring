<script setup lang="ts">
import { onUnmounted, ref } from 'vue'
import { Camera, LoaderCircle, ScanFace } from '@lucide/vue'
import { recognizeFace } from '@/Services/faceService.js'

defineProps<{
    employees: Array<{
        employee_id: string
        first_name: string
        last_name: string
    }>
}>()

const videoRef = ref<HTMLVideoElement | null>(null)
const canvasRef = ref<HTMLCanvasElement | null>(null)
const stream = ref<MediaStream | null>(null)
const isCameraReady = ref(false)
const isProcessing = ref(false)
const message = ref('Diagnostics ready.')
const resultText = ref('')

const startCamera = async () => {
    stream.value = await navigator.mediaDevices.getUserMedia({
        video: { width: { ideal: 960 }, height: { ideal: 540 }, facingMode: 'user' },
        audio: false,
    })

    if (!videoRef.value) return

    videoRef.value.srcObject = stream.value
    await new Promise<void>((resolve) => {
        videoRef.value!.onloadedmetadata = async () => {
            await videoRef.value?.play()
            isCameraReady.value = true
            resolve()
        }
    })
}

const captureBlob = (): Blob | null => {
    const video = videoRef.value
    const canvas = canvasRef.value
    if (!video || !canvas || !isCameraReady.value) return null

    canvas.width = video.videoWidth
    canvas.height = video.videoHeight
    const context = canvas.getContext('2d')
    if (!context) return null

    context.drawImage(video, 0, 0, canvas.width, canvas.height)
    const dataUrl = canvas.toDataURL('image/jpeg', 0.82)
    const byteString = atob(dataUrl.split(',')[1])
    const buffer = new Uint8Array(byteString.length)

    for (let index = 0; index < byteString.length; index++) {
        buffer[index] = byteString.charCodeAt(index)
    }

    return new Blob([buffer], { type: 'image/jpeg' })
}

const runDiagnostic = async () => {
    const image = captureBlob()
    if (!image) {
        message.value = 'Camera image is not ready.'
        return
    }

    isProcessing.value = true
    message.value = 'Checking broad recognition...'
    resultText.value = ''

    try {
        const payload = await recognizeFace(image)
        resultText.value = payload.matched
            ? `Matched ${payload.employee_id} with ${Math.round((payload.confidence || 0) * 100)}% confidence.`
            : payload.message || 'No match.'
        message.value = 'Diagnostic complete.'
    } catch (error) {
        message.value = error instanceof Error ? error.message : 'Diagnostic failed.'
    } finally {
        isProcessing.value = false
    }
}

onUnmounted(() => {
    stream.value?.getTracks().forEach((track) => track.stop())
})
</script>

<template>
    <section
        class="mx-auto flex max-w-3xl flex-col gap-5 rounded-3xl border-2 border-brand-stroke bg-brand-card p-6 text-brand-stroke shadow-[8px_8px_0px_0px_#001e1d]"
    >
        <div class="flex items-center gap-3">
            <ScanFace class="h-8 w-8" />
            <h1 class="text-xl font-black">Face Diagnostics</h1>
        </div>

        <div class="relative aspect-video overflow-hidden rounded-2xl border-2 border-brand-stroke bg-brand-stroke">
            <video ref="videoRef" autoplay playsinline muted class="h-full w-full object-cover" />
            <canvas ref="canvasRef" class="hidden" />
        </div>

        <div class="grid grid-cols-2 gap-3">
            <button
                type="button"
                class="inline-flex items-center justify-center gap-2 rounded-xl border-2 border-brand-stroke bg-brand-accent px-4 py-3 text-sm font-black shadow-[3px_3px_0px_0px_#001e1d] active:translate-x-1 active:translate-y-1 active:shadow-none"
                @click="startCamera"
            >
                <Camera class="h-4 w-4" />
                Camera
            </button>
            <button
                type="button"
                class="inline-flex items-center justify-center gap-2 rounded-xl border-2 border-brand-stroke bg-brand-headline px-4 py-3 text-sm font-black shadow-[3px_3px_0px_0px_#001e1d] active:translate-x-1 active:translate-y-1 active:shadow-none disabled:opacity-60"
                :disabled="!isCameraReady || isProcessing"
                @click="runDiagnostic"
            >
                <LoaderCircle v-if="isProcessing" class="h-4 w-4 animate-spin" />
                <ScanFace v-else class="h-4 w-4" />
                Test
            </button>
        </div>

        <p class="text-sm font-bold text-brand-bg">{{ message }}</p>
        <p v-if="resultText" class="text-sm font-black">{{ resultText }}</p>
    </section>
</template>
