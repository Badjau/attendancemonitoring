<script setup lang="ts">
import {Head} from '@inertiajs/vue3';
import FeedAndStaff from "@/Components/Home/FeedAndStaff.vue";
import PresentToday from "@/Components/Home/PresentToday.vue";
import CameraCard from "@/Components/Home/CameraCard.vue";
import GreetingsCard from "@/Components/Home/GreetingsCard.vue";
import {Toast} from "primevue"

const props = defineProps<{
    attendanceToday: any
    currentMonthBirthdayCelebrants: any
}>();

</script>

<template>
    <Head title="Home"/>

    <Toast
        position="top-right"
        :pt="{
            root: { class: 'flex flex-col gap-3 w-80' },
            message: ({ props }) => ({
                class: [
                    'relative flex items-center gap-3 px-5 py-4 rounded-2xl border-2 border-brand-stroke shadow-[6px_6px_0px_0px_#001e1d] animate-fade-up',
                    {
                        'bg-brand-accent text-brand-stroke'       : props.message.severity === 'success',
                        'bg-brand-tertiary text-brand-headline'   : props.message.severity === 'error',
                        'bg-blue-100 text-blue-800'               : props.message.severity === 'info',
                        'bg-yellow-100 text-yellow-800'           : props.message.severity === 'warn',
                    }
                ]
            }),
            messageIcon: { class: 'w-5 h-5 shrink-0' },
            messageText: { class: 'font-black text-sm uppercase tracking-tight' },
            summary:     { class: 'font-black text-sm uppercase tracking-tight' },
            detail:      { class: 'font-medium text-xs opacity-80' },
            closeButton: { class: 'shrink-0 p-1 rounded-lg opacity-60 hover:opacity-100 transition-opacity' },
            closeIcon:   { class: 'w-4 h-4' },
        }"
    />


    <div class="min-h-screen p-4 md:p-8 flex flex-col antialiased">
        <!-- Toast Notification Container -->
        <main
            class="max-w-450 w-full mx-auto grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-8 grow items-stretch"
        >
            <!-- ================= 1. RIGHT COLUMN: Feed & Staff ================= -->
            <FeedAndStaff :current-month-birthday-celebrants="props.currentMonthBirthdayCelebrants"/>

            <!-- ================= 2. CENTER COLUMN: Camera ================= -->
            <section
                class="flex flex-col gap-3 col-span-1 md:col-span-2 animate-fade-up order-1 xl:order-2"
                style="animation-delay: 0.2s"
            >
                <!-- Camera Card -->
                <CameraCard/>

                <!-- Greetings -->
                <GreetingsCard/>
            </section>

            <!-- ================= 3. LEFT COLUMN: Present Today ================= -->
            <PresentToday :attendance-today="props.attendanceToday"/>
        </main>
    </div>
</template>

<style>
/* Custom High-Contrast Text */
.text-headline {
    color: #fffffe;
}

.text-main {
    color: #001e1d;
}

/* Video Styling */
video {
    transition: opacity 0.5s ease-in-out;
    opacity: 0;
    transform: scaleX(-1);
}

video.loaded {
    opacity: 1;
}

/* Custom Scrollbar for Happy Hues Theme */
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}

.custom-scrollbar::-webkit-scrollbar-track {
    background: rgba(0, 30, 29, 0.1);
}

.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #abd1c6;
    border-radius: 10px;
}

.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #f9bc60;
}

/* Smooth Animations */
@keyframes slideIn {
    from {
        transform: translateY(20px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.animate-fade-up {
    animation: slideIn 0.4s ease-out forwards;
}
</style>

