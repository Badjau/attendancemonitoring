<script setup lang="ts">
import {ArrowLeft, Bell, Paperclip} from "@lucide/vue";
import {Head, Link} from "@inertiajs/vue3";
import {computed} from "vue";
import {useDateFormat} from "@/Composables/useDateFormat";
import {useAnnouncementPresentation} from "@/Composables/useAnnouncementPresentation";

const props = defineProps<{
    announcements: {
        data: any[]
        from: number | null
        to: number | null
        total: number
        links: {
            url: string | null
            label: string
            active: boolean
        }[]
    }
}>();

const {formatDate} = useDateFormat();
const {
    getAnnouncementAttachments,
    getAnnouncementStyle,
    getAttachmentName,
    getAttachmentUrl,
    isImageAttachment,
} = useAnnouncementPresentation();

const formatAnnouncementDate = (date: any) => {
    if (!date) return "Announcement";

    return formatDate(typeof date === "number" ? date * 1000 : date);
};

const announcements = computed(() => props.announcements.data ?? []);

const paginationLinks = computed(() => props.announcements.links ?? []);

const cleanPaginationLabel = (label: string) => label
    .replace("&laquo;", "")
    .replace("&raquo;", "")
    .trim();
</script>

<template>
    <Head title="Announcements"/>

    <main class="min-h-screen bg-brand-bg px-4 py-6 text-brand-stroke md:px-8">
        <section class="mx-auto flex w-full max-w-6xl flex-col gap-6">
            <header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                <div>
                    <Link
                        href="/"
                        class="mb-5 inline-flex items-center gap-2 rounded-xl border-2 border-brand-stroke bg-brand-card px-4 py-2 text-xs font-black uppercase shadow-[4px_4px_0px_0px_#001e1d] transition-transform hover:-translate-y-0.5"
                    >
                        <ArrowLeft class="h-4 w-4"/>
                        Back
                    </Link>

                    <div class="flex items-center gap-3">
                        <div class="rounded-xl border-2 border-brand-stroke bg-brand-accent p-3">
                            <Bell class="h-6 w-6"/>
                        </div>
                        <div>
                            <p class="text-xs font-black uppercase text-brand-paragraph">Announcements</p>
                            <h1 class="text-3xl font-black leading-tight text-brand-headline md:text-5xl">
                                Company Updates
                            </h1>
                        </div>
                    </div>
                </div>
            </header>

            <div v-if="!announcements.length"
                 class="rounded-4xl border-2 border-brand-stroke bg-brand-card p-8 text-center shadow-[8px_8px_0px_0px_#001e1d]">
                <p class="font-black">No announcements available.</p>
            </div>

            <div v-else class="grid gap-5">
                <article
                    v-for="announcement in announcements"
                    :key="announcement.id"
                    class="overflow-hidden rounded-4xl border-2 border-brand-stroke bg-brand-card shadow-[8px_8px_0px_0px_#001e1d]"
                >
                    <div
                        class="h-2"
                        :style="{backgroundColor: getAnnouncementStyle(announcement).accent}"
                    />

                    <div class="p-6">
                        <div class="mb-4 flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                            <div class="min-w-0">
                                <p class="mb-1 text-[10px] font-black uppercase text-brand-bg">
                                    {{ formatAnnouncementDate(announcement.published_at) }}
                                </p>
                                <h2 class="text-2xl font-black leading-tight wrap-break-word">
                                    {{ announcement.title }}
                                </h2>
                            </div>

                            <span
                                v-if="announcement.type"
                                class="w-fit rounded-full border border-brand-stroke bg-white px-3 py-1 text-[10px] font-black uppercase"
                                :style="{backgroundColor: getAnnouncementStyle(announcement).soft, color: getAnnouncementStyle(announcement).text}"
                            >
                                {{ getAnnouncementStyle(announcement).label }}
                            </span>
                        </div>

                        <div class="announcement-content text-sm leading-relaxed" v-html="announcement.content"/>

                        <section
                            v-if="getAnnouncementAttachments(announcement).length"
                            class="mt-6 border-t-2 border-brand-stroke/15 pt-5"
                        >
                            <h3 class="mb-3 inline-flex items-center gap-2 text-sm font-black uppercase">
                                <Paperclip class="h-4 w-4"/>
                                Attachments
                            </h3>

                            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                <a
                                    v-for="attachment in getAnnouncementAttachments(announcement)"
                                    :key="attachment.id"
                                    :href="getAttachmentUrl(attachment)"
                                    target="_blank"
                                    rel="noopener"
                                    class="overflow-hidden rounded-2xl border border-brand-stroke bg-white text-brand-stroke transition-transform hover:-translate-y-0.5"
                                >
                                    <img
                                        v-if="isImageAttachment(attachment)"
                                        :src="getAttachmentUrl(attachment)"
                                        :alt="getAttachmentName(attachment)"
                                        class="h-64 w-full object-cover"
                                    />
                                    <div
                                        v-else
                                        class="flex h-24 items-center justify-center bg-brand-paragraph/25"
                                    >
                                        <Paperclip class="h-7 w-7"/>
                                    </div>
                                </a>
                            </div>
                        </section>
                    </div>
                </article>
            </div>

            <nav
                v-if="paginationLinks.length > 3"
                class="flex flex-col gap-4 rounded-3xl border-2 border-brand-stroke bg-brand-card p-4 shadow-[6px_6px_0px_0px_#001e1d] md:flex-row md:items-center md:justify-between"
            >
                <p class="text-xs font-black uppercase text-brand-bg">
                    Showing {{ props.announcements.from }}-{{ props.announcements.to }} of {{
                        props.announcements.total
                    }}
                </p>

                <div class="flex flex-wrap gap-2">
                    <template
                        v-for="(link, linkIndex) in paginationLinks"
                        :key="linkIndex"
                    >
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            class="inline-flex min-h-9 min-w-9 items-center justify-center rounded-xl border border-brand-stroke px-3 text-xs font-black uppercase transition-transform hover:-translate-y-0.5"
                            :class="link.active ? 'bg-brand-accent text-brand-stroke' : 'bg-white text-brand-stroke'"
                        >
                            {{ cleanPaginationLabel(link.label) }}
                        </Link>

                        <span
                            v-else
                            class="inline-flex min-h-9 min-w-9 items-center justify-center rounded-xl border border-brand-stroke/20 bg-white/40 px-3 text-xs font-black uppercase text-brand-stroke/40"
                        >
                            {{ cleanPaginationLabel(link.label) }}
                        </span>
                    </template>
                </div>
            </nav>
        </section>
    </main>
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
