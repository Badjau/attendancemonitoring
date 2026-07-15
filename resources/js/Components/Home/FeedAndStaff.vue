<script setup lang="ts">
import { ArrowRight, Bell, CircleUser, Gift, X } from '@lucide/vue'
import { computed, ref } from 'vue'
import { useDateFormat } from '@/Composables/useDateFormat'
import { useAnnouncementPresentation } from '@/Composables/useAnnouncementPresentation'

const props = defineProps<{
    todayBirthdayCelebrants: any
    announcements: any
}>()

const { formatDate } = useDateFormat()
const {
    getAnnouncementAttachments,
    getAnnouncementStyle,
    getAttachmentName,
    getAttachmentUrl,
    isImageAttachment,
} = useAnnouncementPresentation()
const selectedAnnouncement = ref<any | null>(null)

const latestAnnouncements = computed(() => props.announcements ?? [])

const stripHtml = (content: string) => {
    const document = new DOMParser().parseFromString(content ?? '', 'text/html')

    return document.body.textContent?.replace(/\s+/g, ' ').trim() ?? ''
}

const getAnnouncementExcerpt = (content: string, limit = 50) => {
    const text = stripHtml(content)

    return text.length > limit ? `${text.slice(0, limit).trim()}...` : text
}

const formatAnnouncementDate = (date: any) => {
    if (!date) return 'Announcement'

    return formatDate(typeof date === 'number' ? date * 1000 : date)
}

const openAnnouncement = (announcement: any) => {
    selectedAnnouncement.value = announcement
}

const closeAnnouncement = () => {
    selectedAnnouncement.value = null
}
</script>

<template>
    <aside class="flex h-full flex-col gap-3">
        <div
            class="flex flex-1 flex-col rounded-2xl border border-black/5 bg-white p-4 shadow-xl shadow-black/5 animate-fade-up"
            style="animation-delay: 0.3s"
        >
            <div class="mb-4 flex items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <div
                        class="flex h-11 w-11 items-center justify-center rounded-full bg-brand-bg/10 text-brand-bg"
                    >
                        <Bell class="h-5 w-5" />
                    </div>
                    <div>
                        <p class="text-xs font-black text-brand-bg">
                            Company desk
                        </p>
                        <h2 class="text-2xl font-black text-brand-stroke">
                            Announcements
                        </h2>
                    </div>
                </div>

            </div>

            <div
                class="custom-scrollbar grow space-y-4 overflow-y-auto pr-1 text-brand-stroke"
            >
                <div
                    v-if="!latestAnnouncements.length"
                    class="rounded-xl border border-dashed border-black/15 bg-brand-paragraph/60 p-4"
                >
                    <p class="text-sm font-bold text-black/55">
                        No announcements yet.
                    </p>
                </div>

                <div
                    v-for="announcement in latestAnnouncements"
                    :key="announcement.id"
                    class="relative rounded-xl border border-black/5 bg-white p-4 shadow-sm before:absolute before:left-0 before:top-4 before:bottom-4 before:w-1 before:rounded-full before:bg-(--announcement-accent)"
                    :style="{
                        '--announcement-accent':
                            getAnnouncementStyle(announcement).accent,
                    }"
                >
                    <p
                        class="mb-2 pl-3 text-xs font-bold text-brand-bg"
                    >
                        {{ formatAnnouncementDate(announcement.published_at) }}
                    </p>
                    <div class="mb-2 flex items-start justify-between gap-2 pl-3">
                        <h3 class="text-base font-black leading-snug">
                            {{ announcement.title }}
                        </h3>
                        <span
                            class="shrink-0 rounded-full px-2 py-1 text-[10px] font-black"
                            :style="{
                                backgroundColor:
                                    getAnnouncementStyle(announcement).soft,
                                color: getAnnouncementStyle(announcement).text,
                            }"
                        >
                            {{ getAnnouncementStyle(announcement).label }}
                        </span>
                    </div>
                    <p class="pl-3 text-sm leading-relaxed text-black/60">
                        {{ getAnnouncementExcerpt(announcement.content) }}
                    </p>
                    <p
                        v-if="getAnnouncementAttachments(announcement).length"
                        class="mt-3 pl-3 text-xs font-black text-brand-bg"
                    >
                        {{ getAnnouncementAttachments(announcement).length }}
                        attachment{{
                            getAnnouncementAttachments(announcement).length ===
                            1
                                ? ''
                                : 's'
                        }}
                    </p>
                    <button
                        type="button"
                        class="ml-3 mt-4 inline-flex items-center gap-2 rounded-full bg-brand-bg px-4 py-2 text-xs font-black text-white shadow-md shadow-red-950/10 transition hover:bg-brand-tertiary"
                        @click="openAnnouncement(announcement)"
                    >
                        View more
                        <ArrowRight class="w-3.5 h-3.5" />
                    </button>
                </div>
            </div>
        </div>

        <div
            v-if="props.todayBirthdayCelebrants.length > 0"
            class="flex max-h-64 flex-col rounded-2xl border border-black/5 bg-white p-4 shadow-xl shadow-black/5 animate-fade-up"
            style="animation-delay: 0.1s"
        >
            <div class="mb-4 flex items-center gap-3">
                <div
                    class="flex h-11 w-11 items-center justify-center rounded-full bg-brand-accent/25 text-brand-bg"
                >
                    <Gift class="h-5 w-5" />
                </div>
                <div>
                    <p class="text-xs font-black text-brand-bg">
                        Team moments
                    </p>
                    <h2 class="text-2xl font-black text-brand-stroke">
                        Birthdays
                    </h2>
                </div>
            </div>

            <div
                class="custom-scrollbar grow space-y-3 overflow-y-auto pr-1 text-brand-stroke"
            >
                <div
                    v-for="(
                        celebrant, celebrantsIndex
                    ) in props.todayBirthdayCelebrants"
                    :key="celebrantsIndex"
                    class="flex items-center gap-4 rounded-xl border border-black/5 bg-brand-paragraph/55 p-3"
                >
                    <img
                        v-if="celebrant.media.length > 0"
                        :src="celebrant.media[0].original_url"
                        alt="Employee Profile"
                        class="h-12 w-12 rounded-full object-cover ring-2 ring-brand-bg/15"
                    />
                    <CircleUser v-else class="h-8 w-8 text-brand-bg" />
                    <div class="min-w-0">
                        <p class="truncate text-sm font-black">
                            {{ celebrant.first_name }} {{ celebrant.last_name }}
                        </p>
                        <p class="truncate text-xs font-semibold text-black/55">
                            {{ celebrant.department?.name ?? null }}
                        </p>
                    </div>
                </div>
                <div
                    class="mt-4 rounded-xl border border-dashed border-brand-bg/25 bg-brand-accent/15 p-4 text-center"
                >
                    <p class="text-sm font-bold text-brand-bg">
                        "Enjoy your special day!"
                    </p>
                </div>
            </div>
        </div>
    </aside>

    <!-- Modal for viewing the full details of announcements -->
    <Teleport to="body">
        <div
            v-if="selectedAnnouncement"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4 py-6 backdrop-blur-sm"
            @click.self="closeAnnouncement"
        >
            <article
                class="max-h-[85vh] w-full max-w-2xl overflow-hidden rounded-2xl bg-white shadow-2xl"
            >
                <header
                    class="flex items-start justify-between gap-4 border-b border-black/10 border-t-8 px-6 py-5"
                    :style="{
                        backgroundColor:
                            getAnnouncementStyle(selectedAnnouncement).soft,
                        borderTopColor:
                            getAnnouncementStyle(selectedAnnouncement).accent,
                    }"
                >
                    <div class="min-w-0">
                        <p
                            class="mb-2 text-xs font-black text-brand-bg"
                        >
                            {{
                                getAnnouncementStyle(selectedAnnouncement).label
                            }}
                            Announcement
                        </p>
                        <h2
                            class="wrap-break-word text-2xl font-black leading-tight text-brand-stroke"
                        >
                            {{ selectedAnnouncement.title }}
                        </h2>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white text-brand-stroke shadow-sm ring-1 ring-black/10"
                        @click="closeAnnouncement"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </header>

                <div
                    class="custom-scrollbar max-h-[60vh] overflow-y-auto px-6 py-5 text-brand-stroke"
                >
                    <div
                        class="announcement-content text-sm leading-relaxed"
                        v-html="selectedAnnouncement.content"
                    />

                    <section
                        v-if="
                            getAnnouncementAttachments(selectedAnnouncement)
                                .length
                        "
                        class="mt-6 border-t border-black/10 pt-5"
                    >
                        <div class="grid gap-3 sm:grid-cols-2">
                            <a
                                v-for="attachment in getAnnouncementAttachments(
                                    selectedAnnouncement,
                                )"
                                :key="attachment.id"
                                :href="getAttachmentUrl(attachment)"
                                target="_blank"
                                rel="noopener"
                                class="overflow-hidden rounded-xl border border-black/10 bg-white text-brand-stroke transition-transform hover:-translate-y-0.5"
                            >
                                <img
                                    v-if="isImageAttachment(attachment)"
                                    :src="getAttachmentUrl(attachment)"
                                    :alt="getAttachmentName(attachment)"
                                    class="h-64 w-full object-cover"
                                />
                            </a>
                        </div>
                    </section>
                </div>
            </article>
        </div>
    </Teleport>
</template>

<style scoped>
.announcement-content :deep(p) {
    margin-bottom: 0.75rem;
}

.announcement-content :deep(ul),
.announcement-content :deep(ol) {
    margin: 0.75rem 0 0.75rem 1.25rem;
}

.announcement-content :deep(ul) {
    list-style: disc;
}

.announcement-content :deep(ol) {
    list-style: decimal;
}
</style>
