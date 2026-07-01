<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { ScanFace } from '@lucide/vue'

defineProps<{
    employees: unknown[]
}>()
</script>

<template>
    <section
        class="mx-auto flex max-w-2xl flex-col items-center gap-5 rounded-3xl border-2 border-brand-stroke bg-brand-card p-8 text-center text-brand-stroke shadow-[8px_8px_0px_0px_#001e1d]"
    >
        <ScanFace class="h-12 w-12" />
        <h1 class="text-2xl font-black">Face Recognition Moved</h1>
        <p class="text-sm font-bold text-brand-bg">
            Facial recognition now runs through the local FastAPI service from
            the main kiosk screen.
        </p>
        <Link
            href="/"
            class="rounded-2xl border-2 border-brand-stroke bg-brand-accent px-5 py-3 text-sm font-black shadow-[4px_4px_0px_0px_#001e1d] active:translate-x-1 active:translate-y-1 active:shadow-none"
        >
            Open Kiosk
        </Link>
    </section>
</template>
