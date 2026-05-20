<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { onMounted, onUnmounted, ref } from 'vue'
import {
    ArrowLeft,
    CalendarClock,
    Download,
    RefreshCw,
    Wifi,
    WifiOff,
} from '@lucide/vue'
import Toast from '@/Components/Toast.vue'
import { useSyncStore } from '@/Stores/sync.js'

type OfflineAttendance = {
    offlineId: string
    occurredAt: string
    employeeIdentifier: string
    employeeName?: string
    attendanceMethod: string
    attendanceType: string
    latitude: number
    longitude: number
    location?: string
    locationSource?: string
    imageBlob?: Blob
    imageFileName?: string
    attempts?: number
    lastError?: string | null
    createdAt?: number
    imageUrl?: string
}

const syncStore = useSyncStore()
const records = ref<OfflineAttendance[]>([])
const isLoading = ref(true)
const isSyncing = ref(false)
const isOnline = ref(navigator.onLine)

const formatDateTime = (value?: string) => {
    if (!value) return '-'

    return new Date(value).toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    })
}

const formatType = (value?: string) =>
    value
        ? value
              .split('-')
              .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
              .join(' ')
        : '-'

const csvValue = (value: unknown) => {
    const normalized = String(value ?? '').replace(/\r?\n|\r/g, ' ')

    return `"${normalized.replace(/"/g, '""')}"`
}

const exportCsv = () => {
    if (!records.value.length) return

    const headers = [
        'Employee Name',
        'Employee ID',
        'Date / Time',
        'Attendance Type',
        'Method',
        'Latitude',
        'Longitude',
    ]

    const rows = records.value.map((record) => [
        record.employeeName || '',
        record.employeeIdentifier,
        formatDateTime(record.occurredAt),
        formatType(record.attendanceType),
        formatType(record.attendanceMethod),
        record.latitude,
        record.longitude,
    ])

    const csv = [headers, ...rows]
        .map((row) => row.map(csvValue).join(','))
        .join('\r\n')
    const blob = new Blob([`\uFEFF${csv}`], {
        type: 'text/csv;charset=utf-8;',
    })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    const timestamp = new Date()
        .toISOString()
        .slice(0, 19)
        .replace(/[:T]/g, '-')

    link.href = url
    link.download = `offline-attendance-${timestamp}.csv`
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
}

const releaseImageUrls = () => {
    records.value.forEach((record) => {
        if (record.imageUrl) URL.revokeObjectURL(record.imageUrl)
    })
}

const loadRecords = async () => {
    isLoading.value = true
    releaseImageUrls()

    const queued =
        (await syncStore.getQueuedAttendances()) as OfflineAttendance[]
    records.value = queued.map((record) => ({
        ...record,
        imageUrl: record.imageBlob ? URL.createObjectURL(record.imageBlob) : '',
    }))
    isLoading.value = false
}

const syncNow = async () => {
    isSyncing.value = true
    await syncStore.flushQueue()
    await loadRecords()
    isSyncing.value = false
}

const updateOnlineStatus = () => {
    isOnline.value = navigator.onLine
}

onMounted(() => {
    window.addEventListener('online', updateOnlineStatus)
    window.addEventListener('offline', updateOnlineStatus)
    loadRecords()
})

onUnmounted(() => {
    window.removeEventListener('online', updateOnlineStatus)
    window.removeEventListener('offline', updateOnlineStatus)
    releaseImageUrls()
})
</script>

<template>
    <Head title="Offline Attendance" />

    <Toast />

    <main class="font-mona-sans min-h-screen bg-slate-50 p-4 md:p-8">
        <section class="mx-auto max-w-7xl">
            <div
                class="mb-6 flex flex-col gap-4 md:flex-row md:items-center md:justify-between"
            >
                <div>
                    <Link
                        href="/"
                        class="mb-3 inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-950"
                    >
                        <ArrowLeft class="h-4 w-4" />
                        Back to timeclock
                    </Link>
                    <h1 class="text-2xl font-bold text-slate-950">
                        Offline Attendance
                    </h1>
                    <p class="mt-1 text-sm text-slate-600">
                        Browser-saved attendance records waiting to sync.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <div
                        class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700"
                    >
                        <Wifi v-if="isOnline" class="h-4 w-4 text-green-600" />
                        <WifiOff v-else class="h-4 w-4 text-red-600" />
                        {{ isOnline ? 'Online' : 'Offline' }}
                    </div>

                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="!records.length"
                        @click="exportCsv"
                    >
                        <Download class="h-4 w-4" />
                        Export CSV
                    </button>

                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-300"
                        :disabled="!isOnline || isSyncing || !records.length"
                        @click="syncNow"
                    >
                        <RefreshCw
                            class="h-4 w-4"
                            :class="{ 'animate-spin': isSyncing }"
                        />
                        {{ isSyncing ? 'Syncing...' : 'Sync now' }}
                    </button>
                </div>
            </div>

            <div class="mb-4 flex flex-wrap gap-2 text-sm text-slate-600">
                <span
                    class="rounded-lg border border-slate-200 bg-white px-3 py-2"
                >
                    <strong class="text-slate-950">{{ records.length }}</strong>
                    pending
                </span>
                <span
                    class="rounded-lg border border-slate-200 bg-white px-3 py-2"
                    >Stored on this device</span
                >
                <span
                    class="rounded-lg border border-slate-200 bg-white px-3 py-2"
                    >Oldest syncs first</span
                >
            </div>

            <div
                v-if="isLoading"
                class="rounded-xl border border-slate-200 bg-white p-8 text-center text-slate-600"
            >
                Loading offline records...
            </div>

            <div
                v-else-if="!records.length"
                class="rounded-xl border border-slate-200 bg-white p-10 text-center"
            >
                <CalendarClock class="mx-auto mb-4 h-10 w-10 text-slate-400" />
                <h2 class="text-xl font-semibold text-slate-950">
                    No offline attendance saved
                </h2>
                <p class="mt-2 text-sm text-slate-600">
                    Offline records will appear here until they sync.
                </p>
            </div>

            <div
                v-else
                class="overflow-hidden rounded-xl border border-slate-200 bg-white"
            >
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-base font-semibold text-slate-950">
                        Pending Records
                    </h2>
                    <p class="text-xs text-slate-500">
                        Compact table view for HR review.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table
                        class="w-full min-w-280 border-collapse text-left text-sm"
                    >
                        <thead class="bg-slate-100 text-xs text-slate-600">
                            <tr>
                                <th class="px-4 py-3 font-semibold uppercase">
                                    Attendance Photo
                                </th>
                                <th class="px-4 py-3 font-semibold uppercase">
                                    Employee
                                </th>
                                <th class="px-4 py-3 font-semibold uppercase">
                                    Date / Time
                                </th>
                                <th class="px-4 py-3 font-semibold uppercase">
                                    Type
                                </th>
                                <th class="px-4 py-3 font-semibold uppercase">
                                    Method
                                </th>
                                <th class="px-4 py-3 font-semibold uppercase">
                                    Coordinates
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="record in records"
                                :key="record.offlineId"
                                class="border-b border-slate-100 align-top last:border-b-0 hover:bg-slate-50"
                            >
                                <td class="px-4 py-3">
                                    <img
                                        v-if="record.imageUrl"
                                        :src="record.imageUrl"
                                        :alt="`Offline attendance ${record.offlineId}`"
                                        class="h-16 w-16 rounded-lg object-cover"
                                    />
                                    <div
                                        v-else
                                        class="flex h-16 w-16 items-center justify-center rounded-lg bg-slate-100 text-xs text-slate-500"
                                    >
                                        No image
                                    </div>
                                </td>

                                <td class="px-4 py-3">
                                    <div class="font-semibold text-slate-950">
                                        {{
                                            record.employeeName ||
                                            record.employeeIdentifier
                                        }}
                                    </div>
                                    <div
                                        v-if="record.employeeName"
                                        class="mt-1 text-xs text-slate-500"
                                    >
                                        {{ record.employeeIdentifier }}
                                    </div>
                                </td>

                                <td
                                    class="whitespace-nowrap px-4 py-3 text-slate-700"
                                >
                                    {{ formatDateTime(record.occurredAt) }}
                                </td>

                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold uppercase text-slate-700"
                                    >
                                        {{ formatType(record.attendanceType) }}
                                    </span>
                                </td>

                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold uppercase text-slate-700"
                                    >
                                        {{
                                            formatType(record.attendanceMethod)
                                        }}
                                    </span>
                                </td>

                                <td
                                    class="px-4 py-3 font-mono text-xs text-slate-700"
                                >
                                    <div>{{ record.latitude }}</div>
                                    <div>{{ record.longitude }}</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</template>
