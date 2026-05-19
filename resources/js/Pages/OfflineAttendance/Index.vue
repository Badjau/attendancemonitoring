<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { onMounted, onUnmounted, ref } from 'vue'
import {
    ArrowLeft,
    CalendarClock,
    MapPin,
    RefreshCw,
    Trash2,
    Wifi,
    WifiOff,
} from '@lucide/vue'
import Toast from '@/Components/Toast.vue'
import { useSyncStore } from '@/Stores/sync.js'

type OfflineAttendance = {
    offlineId: string
    occurredAt: string
    employeeIdentifier: string
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

const deleteRecord = async (record: OfflineAttendance) => {
    if (!confirm('Remove this offline attendance record from this device?')) {
        return
    }

    await syncStore.deleteQueuedAttendance(record.offlineId)
    await loadRecords()
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

    <main class="min-h-screen p-4 md:p-8">
        <section class="mx-auto max-w-7xl">
            <div
                class="mb-6 flex flex-col gap-4 md:flex-row md:items-center md:justify-between"
            >
                <div>
                    <Link
                        href="/"
                        class="mb-3 inline-flex items-center gap-2 text-sm font-black uppercase text-brand-bg hover:text-brand-stroke"
                    >
                        <ArrowLeft class="h-4 w-4" />
                        Back to timeclock
                    </Link>
                    <h1 class="text-3xl font-black text-brand-stroke">
                        Offline Attendance
                    </h1>
                    <p class="mt-1 text-sm font-bold text-brand-bg">
                        These records are saved on this browser and will sync
                        when the connection is available.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <div
                        class="inline-flex items-center gap-2 rounded-full border-2 border-brand-stroke bg-brand-card px-4 py-2 text-sm font-black text-brand-stroke"
                    >
                        <Wifi v-if="isOnline" class="h-4 w-4 text-green-600" />
                        <WifiOff v-else class="h-4 w-4 text-red-600" />
                        {{ isOnline ? 'Online' : 'Offline' }}
                    </div>

                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-2xl border-2 border-brand-stroke bg-brand-accent px-5 py-3 text-sm font-black text-brand-stroke shadow-[4px_4px_0px_0px_#001e1d] disabled:cursor-not-allowed disabled:bg-white disabled:opacity-60 active:translate-x-1 active:translate-y-1 active:shadow-none"
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

            <div class="mb-6 grid gap-4 md:grid-cols-3">
                <div
                    class="rounded-3xl border-2 border-brand-stroke bg-brand-card p-6 shadow-[6px_6px_0px_0px_#001e1d]"
                >
                    <p class="text-xs font-black uppercase text-brand-bg">
                        Pending Records
                    </p>
                    <p class="mt-2 text-4xl font-black text-brand-stroke">
                        {{ records.length }}
                    </p>
                </div>
                <div
                    class="rounded-3xl border-2 border-brand-stroke bg-brand-card p-6 shadow-[6px_6px_0px_0px_#001e1d]"
                >
                    <p class="text-xs font-black uppercase text-brand-bg">
                        Storage
                    </p>
                    <p class="mt-2 text-xl font-black text-brand-stroke">
                        This device
                    </p>
                </div>
                <div
                    class="rounded-3xl border-2 border-brand-stroke bg-brand-card p-6 shadow-[6px_6px_0px_0px_#001e1d]"
                >
                    <p class="text-xs font-black uppercase text-brand-bg">
                        Sync Order
                    </p>
                    <p class="mt-2 text-xl font-black text-brand-stroke">
                        Oldest first
                    </p>
                </div>
            </div>

            <div
                v-if="isLoading"
                class="rounded-3xl border-2 border-brand-stroke bg-brand-card p-8 text-center text-brand-stroke"
            >
                Loading offline records...
            </div>

            <div
                v-else-if="!records.length"
                class="rounded-3xl border-2 border-brand-stroke bg-brand-card p-10 text-center shadow-[8px_8px_0px_0px_#001e1d]"
            >
                <CalendarClock class="mx-auto mb-4 h-12 w-12 text-brand-bg" />
                <h2 class="text-2xl font-black text-brand-stroke">
                    No offline attendance saved
                </h2>
                <p class="mt-2 text-sm font-bold text-brand-bg">
                    When attendance is recorded offline, it will appear here
                    until it syncs.
                </p>
            </div>

            <div v-else class="grid gap-5">
                <article
                    v-for="record in records"
                    :key="record.offlineId"
                    class="grid gap-4 rounded-3xl border-2 border-brand-stroke bg-brand-card p-5 shadow-[6px_6px_0px_0px_#001e1d] md:grid-cols-[180px_minmax(0,1fr)_auto]"
                >
                    <div
                        class="overflow-hidden rounded-2xl border-2 border-brand-stroke bg-brand-stroke"
                    >
                        <img
                            v-if="record.imageUrl"
                            :src="record.imageUrl"
                            :alt="`Offline attendance ${record.offlineId}`"
                            class="aspect-square h-full w-full object-cover"
                        />
                        <div
                            v-else
                            class="flex aspect-square items-center justify-center text-sm font-bold text-brand-headline"
                        >
                            No image
                        </div>
                    </div>

                    <div class="min-w-0">
                        <div class="mb-3 flex flex-wrap items-center gap-2">
                            <span
                                class="rounded-full border border-brand-stroke bg-white px-3 py-1 text-xs font-black uppercase text-brand-stroke"
                            >
                                {{ formatType(record.attendanceType) }}
                            </span>
                            <span
                                class="rounded-full border border-brand-stroke bg-white px-3 py-1 text-xs font-black uppercase text-brand-stroke"
                            >
                                {{ formatType(record.attendanceMethod) }}
                            </span>
                            <span
                                class="rounded-full border px-3 py-1 text-xs font-black uppercase"
                                :class="
                                    record.locationSource === 'cached'
                                        ? 'border-yellow-700 bg-yellow-50 text-yellow-800'
                                        : 'border-green-700 bg-green-50 text-green-800'
                                "
                            >
                                {{
                                    record.locationSource === 'cached'
                                        ? 'Cached GPS'
                                        : 'Live GPS'
                                }}
                            </span>
                        </div>

                        <h2
                            class="truncate text-xl font-black text-brand-stroke"
                        >
                            Employee: {{ record.employeeIdentifier }}
                        </h2>

                        <dl class="mt-4 grid gap-3 text-sm md:grid-cols-2">
                            <div>
                                <dt class="font-black uppercase text-brand-bg">
                                    Occurred At
                                </dt>
                                <dd class="font-bold text-brand-stroke">
                                    {{ formatDateTime(record.occurredAt) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="font-black uppercase text-brand-bg">
                                    Attempts
                                </dt>
                                <dd class="font-bold text-brand-stroke">
                                    {{ record.attempts ?? 0 }}
                                </dd>
                            </div>
                            <div class="md:col-span-2">
                                <dt
                                    class="flex items-center gap-1 font-black uppercase text-brand-bg"
                                >
                                    <MapPin class="h-4 w-4" />
                                    Coordinates
                                </dt>
                                <dd class="font-bold text-brand-stroke">
                                    {{ record.latitude }},
                                    {{ record.longitude }}
                                </dd>
                            </div>
                            <div v-if="record.location" class="md:col-span-2">
                                <dt class="font-black uppercase text-brand-bg">
                                    Resolved Address
                                </dt>
                                <dd class="font-bold text-brand-stroke">
                                    {{ record.location }}
                                </dd>
                            </div>
                            <div
                                v-if="record.lastError"
                                class="md:col-span-2 rounded-2xl border border-red-700 bg-red-50 p-3 text-red-800"
                            >
                                <dt class="font-black uppercase">Last Error</dt>
                                <dd class="font-bold">
                                    {{ record.lastError }}
                                </dd>
                            </div>
                        </dl>

                        <p
                            class="mt-4 break-all text-xs font-bold text-brand-bg"
                        >
                            Offline ID: {{ record.offlineId }}
                        </p>
                    </div>

                    <div class="flex items-start justify-end">
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-2xl border-2 border-red-700 bg-red-50 px-4 py-3 text-sm font-black text-red-800 shadow-[3px_3px_0px_0px_#7f1d1d] active:translate-x-1 active:translate-y-1 active:shadow-none"
                            @click="deleteRecord(record)"
                        >
                            <Trash2 class="h-4 w-4" />
                            Remove
                        </button>
                    </div>
                </article>
            </div>
        </section>
    </main>
</template>
