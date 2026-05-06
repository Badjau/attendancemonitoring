<script setup lang="ts">
import {ArrowRight, Bell, CircleUser, Gift, X} from "@lucide/vue";
import {Link} from "@inertiajs/vue3";
import {computed, ref} from "vue";
import {useDateFormat} from "@/Composables/useDateFormat";
import {useAnnouncementPresentation} from "@/Composables/useAnnouncementPresentation";

const props = defineProps<{
    todayBirthdayCelebrants: any
    announcements: any
}>();

const {formatDate} = useDateFormat();
const {
    getAnnouncementAttachments,
    getAnnouncementStyle,
    getAttachmentName,
    getAttachmentUrl,
    isImageAttachment,
} = useAnnouncementPresentation();
const selectedAnnouncement = ref<any | null>(null);

const latestAnnouncements = computed(() => props.announcements ?? []);

const stripHtml = (content: string) => {
    const document = new DOMParser().parseFromString(content ?? "", "text/html");

    return document.body.textContent?.replace(/\s+/g, " ").trim() ?? "";
};

const getAnnouncementExcerpt = (content: string, limit = 50) => {
    const text = stripHtml(content);

    return text.length > limit ? `${text.slice(0, limit).trim()}...` : text;
};

const formatAnnouncementDate = (date: any) => {
    if (!date) return "Announcement";

    return formatDate(typeof date === "number" ? date * 1000 : date);
};

const openAnnouncement = (announcement: any) => {
    selectedAnnouncement.value = announcement;
};

const closeAnnouncement = () => {
    selectedAnnouncement.value = null;
};
</script>

<template>
    <aside class="order-3 xl:order-1 flex flex-col gap-8">
        <!-- Announcements -->
        <div
            class="bg-brand-card rounded-4xl p-8 shadow-[8px_8px_0px_0px_#001e1d] border-2 border-brand-stroke grow flex flex-col animate-fade-up"
            style="animation-delay: 0.3s"
        >
            <div class="flex items-center justify-between gap-3 mb-6">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="p-2 bg-brand-accent rounded-xl border border-brand-stroke">
                        <Bell class="w-5 h-5 text-brand-stroke"/>
                    </div>
                    <h2 class="text-xl font-black text-brand-stroke">Latest</h2>
                </div>

<!--                <Link-->
<!--                    href="/announcements"-->
<!--                    class="inline-flex items-center gap-1 text-[11px] font-black uppercase text-brand-bg hover:text-brand-stroke transition-colors"-->
<!--                >-->
<!--                    View all-->
<!--                    <ArrowRight class="w-3.5 h-3.5"/>-->
<!--                </Link>-->
            </div>

            <div class="space-y-5 grow custom-scrollbar overflow-y-auto pr-2 text-brand-stroke">
                <div v-if="!latestAnnouncements.length" class="rounded-2xl border border-brand-stroke/10 bg-white/45 p-4">
                    <p class="text-sm font-bold">No announcements yet.</p>
                </div>

                <div
                    v-for="announcement in latestAnnouncements"
                    :key="announcement.id"
                    class="relative pl-6 before:absolute before:left-0 before:top-0 before:bottom-0 before:w-1 before:rounded-full before:bg-[var(--announcement-accent)]"
                    :style="{'--announcement-accent': getAnnouncementStyle(announcement).accent}"
                >
                    <p class="text-[10px] font-black text-brand-bg uppercase mb-1">
                        {{ formatAnnouncementDate(announcement.published_at) }}
                    </p>
                    <div class="mb-1 flex items-start justify-between gap-2">
                        <h3 class="text-sm font-bold">{{ announcement.title }}</h3>
                        <span
                            class="shrink-0 rounded-full border border-brand-stroke px-2 py-0.5 text-[9px] font-black uppercase"
                            :style="{backgroundColor: getAnnouncementStyle(announcement).soft, color: getAnnouncementStyle(announcement).text}"
                        >
                            {{ getAnnouncementStyle(announcement).label }}
                        </span>
                    </div>
                    <p class="text-xs opacity-70 leading-relaxed">
                        {{ getAnnouncementExcerpt(announcement.content) }}
                    </p>
                    <p
                        v-if="getAnnouncementAttachments(announcement).length"
                        class="mt-2 text-[10px] font-black uppercase text-brand-bg"
                    >
                        {{ getAnnouncementAttachments(announcement).length }} attachment{{ getAnnouncementAttachments(announcement).length === 1 ? '' : 's' }}
                    </p>
                    <button
                        type="button"
                        class="mt-3 inline-flex items-center gap-1 rounded-xl border border-brand-stroke bg-white px-3 py-1.5 text-[11px] font-black uppercase text-brand-stroke shadow-[3px_3px_0px_0px_#001e1d] transition-transform hover:-translate-y-0.5"
                        @click="openAnnouncement(announcement)"
                    >
                        View more
                        <ArrowRight class="w-3.5 h-3.5"/>
                    </button>
                </div>
            </div>
        </div>

        <!-- Birthdays Card -->
        <div
            class="bg-brand-card rounded-4xl p-8 shadow-[8px_8px_0px_0px_#001e1d] border-2 border-brand-stroke grow flex flex-col animate-fade-up"
            style="animation-delay: 0.1s"
        >
            <div class="flex items-center gap-3 mb-6">
                <div class="p-2 bg-brand-paragraph rounded-xl border border-brand-stroke">
                    <Gift class="w-5 h-5 text-brand-bg"/>
                </div>
                <h2 class="text-xl font-black text-brand-stroke">
                    Today's Birthday Celebrants
                </h2>
            </div>

            <div
                class="space-y-4 grow custom-scrollbar overflow-y-auto pr-2 text-brand-stroke"
            >
                <div
                    v-for="(celebrant, celebrantsIndex) in props.todayBirthdayCelebrants"
                    :key="celebrantsIndex"
                    class="flex items-center gap-4 p-3 rounded-2xl bg-white/50 border border-brand-stroke/10"
                >
                    <img
                        v-if="celebrant.media.length > 0"
                        :src="celebrant.media[0].original_url"
                        alt="Employee Profile"
                        class="w-12 h-12 rounded-full border-2 border-brand-stroke"
                    />
                    <CircleUser
                        v-else
                        class="w-8 h-8"
                    />
                    <div>
                        <p class="font-bold text-sm">{{ celebrant.first_name }} {{ celebrant.last_name }}</p>
                        <p class="text-xs opacity-70">{{ formatDate(celebrant.date_of_birth) }}</p>
                        <p class="text-xs opacity-70">{{ celebrant.department?.name ?? null }}</p>
                    </div>
                </div>
                <div
                    class="mt-6 p-4 bg-brand-accent/20 rounded-2xl text-center border-2 border-dashed"
                >
                    <p class="text-sm font-medium italic text-brand-bg">
                        "Enjoy your special day!"
                    </p>
                </div>
            </div>
        </div>
    </aside>

    <Teleport to="body">
        <div
            v-if="selectedAnnouncement"
            class="fixed inset-0 z-50 flex items-center justify-center bg-brand-stroke/70 px-4 py-6"
            @click.self="closeAnnouncement"
        >
            <article
                class="w-full max-w-2xl max-h-[85vh] overflow-hidden rounded-3xl border-2 border-brand-stroke bg-brand-card shadow-[8px_8px_0px_0px_#001e1d]"
            >
                <header
                    class="flex items-start justify-between gap-4 border-b-2 border-t-8 border-brand-stroke px-6 py-5"
                    :style="{
                        backgroundColor: getAnnouncementStyle(selectedAnnouncement).soft,
                        borderTopColor: getAnnouncementStyle(selectedAnnouncement).accent,
                    }"
                >
                    <div class="min-w-0">
                        <p class="mb-2 text-[10px] font-black uppercase text-brand-stroke">
                            {{ getAnnouncementStyle(selectedAnnouncement).label }} Announcement
                        </p>
                        <h2 class="text-2xl font-black leading-tight text-brand-stroke break-words">
                            {{ selectedAnnouncement.title }}
                        </h2>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-brand-stroke bg-white text-brand-stroke"
                        @click="closeAnnouncement"
                    >
                        <X class="h-5 w-5"/>
                    </button>
                </header>

                <div class="custom-scrollbar max-h-[60vh] overflow-y-auto px-6 py-5 text-brand-stroke">
                    <div class="announcement-content text-sm leading-relaxed" v-html="selectedAnnouncement.content"/>

                    <section
                        v-if="getAnnouncementAttachments(selectedAnnouncement).length"
                        class="mt-6 border-t-2 border-brand-stroke/15 pt-5"
                    >
                        <div class="grid gap-3 sm:grid-cols-2">
                            <a
                                v-for="attachment in getAnnouncementAttachments(selectedAnnouncement)"
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
