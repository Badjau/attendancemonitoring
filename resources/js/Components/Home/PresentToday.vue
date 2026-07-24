<script setup lang="ts">
import { CircleUser, Users } from '@lucide/vue'
import { computed } from 'vue'

const props = defineProps<{
    attendanceToday: any
    activeBranch?: string
}>()

const normalizeBranch = (branch?: string | null) =>
    (branch || '').trim().toLowerCase()

const filteredAttendanceToday = computed(() => {
    const branch = normalizeBranch(props.activeBranch)

    if (!branch) {
        return props.attendanceToday
    }

    return props.attendanceToday.filter(
        (attendance: any) =>
            normalizeBranch(attendance?.employee?.branch) === branch,
    )
})
</script>

<template>
    <aside class="flex min-h-0 flex-1 flex-col">
        <div
            class="flex min-h-0 grow flex-col rounded-2xl border border-black/5 bg-white p-4 shadow-xl shadow-black/5 animate-fade-up"
            style="animation-delay: 0.4s"
        >
            <div class="mb-4 flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div
                        class="flex h-11 w-11 items-center justify-center rounded-full bg-brand-bg/10 text-brand-bg"
                    >
                        <Users class="h-5 w-5" />
                    </div>
                    <div>
                        <p class="text-xs font-black text-brand-bg">
                            Live roster
                        </p>
                    </div>
                </div>
                <span
                    class="rounded-full bg-brand-bg px-3 py-1 text-m font-black text-white"
                    id="present-count"
                    v-if="filteredAttendanceToday.length > 0"
                >
                    {{ filteredAttendanceToday.length }}
                </span>
            </div>

            <p
                v-if="props.activeBranch"
                class="mb-4 rounded-full bg-brand-paragraph px-3 py-1 text-xs font-bold text-brand-bg"
            >
                {{ props.activeBranch }}
            </p>

            <div
                class="custom-scrollbar grow space-y-3 overflow-y-auto pr-1"
                id="timed-in-list"
            >
                <div
                    v-if="filteredAttendanceToday.length === 0"
                    class="rounded-xl border border-dashed border-black/15 bg-brand-paragraph/60 p-4 text-sm font-semibold text-black/55"
                >
                    No employees are currently listed as present.
                </div>

                <div
                    v-for="(
                        attendance, attendanceIndex
                    ) in filteredAttendanceToday"
                    :key="attendanceIndex"
                    class="flex items-center gap-3 rounded-xl border border-black/5 bg-white p-3 shadow-sm transition hover:border-brand-bg/20 hover:bg-brand-paragraph/50"
                >
                    <img
                        v-if="attendance?.employee?.media.length > 0"
                        :src="attendance?.employee?.media[0].original_url"
                        alt="Employee Profile"
                        class="h-12 w-12 rounded-full object-cover ring-2 ring-brand-bg/15"
                    />
                    <CircleUser v-else class="h-8 w-8 text-brand-bg" />

                    <div class="min-w-0">
                        <p class="truncate text-sm font-black text-brand-stroke">
                            {{ attendance.employee.first_name }}
                            {{ attendance.employee.last_name }}
                        </p>
                        <p class="truncate text-xs font-semibold text-black/55">
                            {{ attendance.employee.position }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </aside>
</template>

<style scoped></style>
