<script setup lang="ts">
import {Head} from '@inertiajs/vue3';
import FaceRegistrationCamera from '@/Components/Face/FaceRegistrationCamera.vue';
import Toast from '@/Components/Toast.vue';

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
}>();
</script>

<template>
    <Head title="Register Face"/>

    <Toast/>

    <main class="min-h-screen p-4 md:p-8">
        <FaceRegistrationCamera :employees="employees"/>
    </main>
</template>
