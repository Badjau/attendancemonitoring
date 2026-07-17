<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
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
        time_out_start: string
        max_breaks_per_day: string
        first_break_limit_minutes: string
        additional_break_limit_minutes: string
        duplicate_scan_window_seconds: string
        same_employee_auth_cooldown_minutes: string
        face_capture_width_ratio: string
        face_capture_height_ratio: string
        face_verification_window_ms: string
        face_usable_frame_target: string
        face_required_match_count: string
        face_only_usable_frame_target: string
        face_only_required_match_count: string
        show_face_attendance_button: boolean
        show_scan_status_messages: boolean
    }
    scanStatusMessages: {
        idle: string
        rfid_not_recognized: string
        fingerprint_waiting: string
        fingerprint_not_found: string
        fingerprint_matched: string
        attendance_recorded: string
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

const refreshAttendanceToday = (employeeId?: string, branch?: string) => {
    const requestedBranch = branch?.trim() || activeBranch.value

    router.reload({
        only: ['attendanceToday'],
        data: requestedBranch ? { branch: requestedBranch } : {},
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
    refreshAttendanceToday(employeeId, branch)
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

    <div class="min-h-screen bg-[#f0eeea] text-brand-stroke antialiased">
        <header
            class="sticky top-0 z-30 border-b border-black/5 bg-white/95 px-4 py-3 shadow-sm backdrop-blur md:px-8"
        >
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <a href="/">
                        <img 
                            src="/images/mcasia-logo.png" 
                            alt="McAsia" 
                            class="h-12 w-12 rounded bg-white object-contain p-1 shadow-sm ring-1 ring-black/10 md:h-12 md:w-12" 
                        />
                    </a>
                    <div class="leading-tight">
                        <p class="text-sm font-black text-brand-bg md:text-base">
                            McAsia Attendance
                        </p>
                        <p class="text-xs font-semibold text-black/55">
                            Workforce monitoring system
                        </p>
                    </div>
                </div>

                <Link
                    href="/unlock"
                    class="inline-flex items-center justify-center rounded-full bg-brand-bg px-3 py-2 text-2xl font-black text-white shadow-lg shadow-red-950/15 transition hover:bg-brand-tertiary focus:outline-none focus:ring-4 focus:ring-brand-bg/20"
                >
                    🔐
                </Link>
            </div>
        </header>

        <main class="mx-auto w-full max-w-[94rem] px-3 py-4 md:px-5 md:py-5">
            
            <section
                class="grid grid-cols-1 gap-4 lg:grid-cols-[340px_minmax(0,1fr)_340px] xl:grid-cols-[380px_minmax(0,1.05fr)_380px]"
            >
                <FeedAndStaff
                    :today-birthday-celebrants="props.todayBirthdayCelebrants"
                    :announcements="props.announcements"
                />

                <div class="flex flex-col gap-3">
                    <GreetingsCard />
                    <CameraCard
                        :employees="props.employeesWithFaces"
                        :attendance-schedule="props.attendanceSchedule"
                        :scan-status-messages="props.scanStatusMessages"
                        :zkteco-bridge-url="props.zktecoBridgeUrl"
                        @employee-verified="setActiveBranch"
                    />
                </div>

                <div class="flex flex-col gap-3">
                    <section
                        class="rounded-2xl bg-brand-bg p-4 text-white shadow-xl shadow-red-950/15"
                    >
                        <div class="grid grid-cols-3 gap-2 text-center">
                            <div class="rounded-xl bg-white/15 p-2">
                                <p class="text-xl font-black">
                                    {{ props.attendanceToday.length }}
                                </p>
                                <p class="text-[11px] font-bold text-white/75">
                                    Present
                                </p>
                            </div>
                            <div class="rounded-xl bg-white/15 p-2">
                                <p class="text-xl font-black">
                                    {{ props.announcements.length }}
                                </p>
                                <p class="text-[11px] font-bold text-white/75">
                                    Updates
                                </p>
                            </div>
                            <div class="rounded-xl bg-white/15 p-2">
                                <p class="text-xl font-black">
                                    {{ props.todayBirthdayCelebrants.length }}
                                </p>
                                <p class="text-[11px] font-bold text-white/75">
                                    Birthdays
                                </p>
                            </div>
                        </div>
                    </section>

                    <PresentToday
                        :attendance-today="props.attendanceToday"
                        :active-branch="activeBranch"
                    />
                </div>
            </section>

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
