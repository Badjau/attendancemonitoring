<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import FaceRecognitionClock from '@/Components/Face/FaceRecognitionClock.vue'
import Toast from '@/Components/Toast.vue'

type FaceEmployee = {
    id: number
    employee_id: string
    first_name: string
    last_name: string
    position: string
    profile_url: string
}

defineProps<{
    employees: FaceEmployee[]
}>()
</script>

<template>
    <Head title="Facial Recognition" />

    <Toast />

    <main class="min-h-screen p-4 md:p-8">
        <FaceRecognitionClock :employees="employees" />
    </main>
</template>
