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
    <aside class="order-1 xl:order-3 flex flex-col gap-8">
        <!-- Present Today -->
        <div
            class="bg-brand-card rounded-4xl p-8 shadow-[8px_8px_0px_0px_#001e1d] border-2 border-brand-stroke grow flex flex-col animate-fade-up"
            style="animation-delay: 0.4s"
        >
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-3">
                    <div
                        class="p-2 bg-brand-paragraph rounded-xl border border-brand-stroke"
                    >
                        <Users class="w-5 h-5 text-brand-bg" />
                    </div>
                    <h2 class="text-xl font-black text-brand-stroke">
                        Present
                    </h2>
                </div>
                <span
                    class="text-xs font-bold bg-brand-bg text-brand-headline px-3 py-1 rounded-full"
                    id="present-count"
                    v-if="filteredAttendanceToday.length > 0"
                >
                    {{ filteredAttendanceToday.length }}
                </span>
            </div>

            <p
                v-if="props.activeBranch"
                class="mb-4 text-xs font-bold uppercase tracking-wide text-brand-bg"
            >
                {{ props.activeBranch }}
            </p>

            <div
                class="space-y-3 grow custom-scrollbar overflow-y-auto pr-2"
                id="timed-in-list"
            >
                <div
                    v-for="(
                        attendance, attendanceIndex
                    ) in filteredAttendanceToday"
                    :key="attendanceIndex"
                    class="flex items-center gap-3 p-2 rounded-xl hover:bg-brand-paragraph/20 transition-colors"
                >
                    <img
                        v-if="attendance?.employee?.media.length > 0"
                        :src="attendance?.employee?.media[0].original_url"
                        alt="Employee Profile"
                        class="w-12 h-12 rounded-full border-2 border-brand-stroke object-cover"
                    />
                    <CircleUser v-else class="w-8 h-8 text-brand-stroke" />

                    <div>
                        <p class="font-bold text-brand-stroke text-sm">
                            {{ attendance.employee.first_name }}
                            {{ attendance.employee.last_name }}
                        </p>
                        <p class="text-xs text-brand-bg font-medium">
                            {{ attendance.employee.position }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </aside>
</template>

<style scoped></style>
