<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { onMounted, onUnmounted, ref } from 'vue'
import FeedAndStaff from '@/Components/Home/FeedAndStaff.vue'
import PresentToday from '@/Components/Home/PresentToday.vue'
import CameraCard from '@/Components/Home/CameraCard.vue'
import GreetingsCard from '@/Components/Home/GreetingsCard.vue'
import Toast from '@/Components/Toast.vue'

const props = defineProps<{
    attendanceToday: any
    todayBirthdayCelebrants: any
    announcements: any
    employeesWithFaces: any
    attendanceSchedule: {
        time_in_start: string
        time_in_end: string
        time_out_start: string
        time_out_end: string
    }
    zktecoBridgeUrl: string
}>()

const activeBranch = ref('')

const employeeIdFromPayload = (payload: any) =>
    payload?.employee?.employee_id ??
    payload?.data?.employee?.employee_id ??
    payload?.employee_id ??
    ''

const branchFromPayload = (payload: any) =>
    payload?.employee?.branch ??
    payload?.data?.employee?.branch ??
    payload?.employee_branch ??
    ''

const branchFromAttendance = (employeeId?: string) => {
    if (!employeeId) return ''

    const attendance = props.attendanceToday.find(
        (attendance: any) =>
            String(attendance?.employee?.employee_id ?? '') ===
            String(employeeId),
    )

    return attendance?.employee?.branch?.trim() || ''
}

const refreshAttendanceToday = (employeeId?: string) => {
    router.reload({
        only: ['attendanceToday'],
        data: activeBranch.value ? { branch: activeBranch.value } : {},
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            if (activeBranch.value || !employeeId) return

            activeBranch.value = branchFromAttendance(employeeId)
        },
    })
}

const setActiveBranch = (employee?: { branch?: string | null }) => {
    activeBranch.value = employee?.branch?.trim() || ''
}

const handleAttendanceRecorded = (event: Event) => {
    const payload = (event as CustomEvent)?.detail?.payload
    const employeeId = employeeIdFromPayload(payload)
    const branch = branchFromPayload(payload)

    setActiveBranch({ branch })
    refreshAttendanceToday(employeeId)
}

onMounted(() => {
    window.addEventListener('attendance:recorded', handleAttendanceRecorded)
})

onUnmounted(() => {
    window.removeEventListener('attendance:recorded', handleAttendanceRecorded)
})
</script>

<template>
    <Head title="Home" />

    <Toast />

    <div class="min-h-screen p-4 md:p-8 flex flex-col antialiased">
        <header class="fixed left-3 top-3 z-20 md:left-5 md:top-5">
            <div class="flex items-center gap-3">
                <img
                    src="/images/mcasia-logo.png"
                    alt="TimeClock logo"
                    class="h-11 w-11 rounded-xl border-2 border-brand-stroke bg-brand-card object-contain p-1.5 shadow-[3px_3px_0px_0px_#001e1d] md:h-12 md:w-12"
                />
            </div>
        </header>

        <!-- Toast Notification Container -->
        <main
            class="max-w-450 w-full mx-auto grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-8 grow items-stretch"
        >
            <!-- ================= 1. RIGHT COLUMN: Feed & Staff ================= -->
            <FeedAndStaff
                :today-birthday-celebrants="props.todayBirthdayCelebrants"
                :announcements="props.announcements"
            />

            <!-- ================= 2. CENTER COLUMN: Camera ================= -->
            <section
                class="flex flex-col gap-3 col-span-1 md:col-span-2 animate-fade-up order-1 xl:order-2"
                style="animation-delay: 0.2s"
            >
                <!-- Camera Card -->
                <CameraCard
                    :employees="props.employeesWithFaces"
                    :attendance-schedule="props.attendanceSchedule"
                    :zkteco-bridge-url="props.zktecoBridgeUrl"
                    @employee-verified="setActiveBranch"
                />

                <!-- Greetings -->
                <GreetingsCard />
            </section>

            <!-- ================= 3. LEFT COLUMN: Present Today ================= -->
            <PresentToday
                :attendance-today="props.attendanceToday"
                :active-branch="activeBranch"
            />
        </main>
    </div>
</template>

<style>
/* Video Styling */
.home-camera-video {
    transition: opacity 0.5s ease-in-out;
    opacity: 0;
}

.home-camera-video.loaded {
    opacity: 1;
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
