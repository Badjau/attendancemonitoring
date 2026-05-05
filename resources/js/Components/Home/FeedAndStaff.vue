<script setup lang="ts">
import {Bell, Gift, CircleUser} from "@lucide/vue";
import {useDateFormat} from "@/Composables/useDateFormat";

const props = defineProps<{
    currentMonthBirthdayCelebrants: any
}>();

const {formatDate} = useDateFormat();
</script>

<template>
    <aside class="order-3 xl:order-1 flex flex-col gap-8">
        <!-- Announcements -->
        <div
            class="bg-brand-card rounded-4xl p-8 shadow-[8px_8px_0px_0px_#001e1d] border-2 border-brand-stroke grow flex flex-col animate-fade-up"
            style="animation-delay: 0.3s"
        >
            <div class="flex items-center gap-3 mb-6">
                <div class="p-2 bg-brand-accent rounded-xl border border-brand-stroke">
                    <Bell class="w-5 h-5 text-brand-stroke"/>
                </div>
                <h2 class="text-xl font-black text-brand-stroke">Latest</h2>
            </div>

            <div
                class="space-y-6 grow custom-scrollbar overflow-y-auto pr-2 text-brand-stroke"
            >
                <div
                    class="relative pl-6 before:absolute before:left-0 before:top-0 before:bottom-0 before:w-1 before:bg-brand-accent before:rounded-full"
                >
                    <p class="text-[10px] font-black text-brand-bg uppercase mb-1">
                        Today • 9:00 AM
                    </p>
                    <h3 class="text-sm font-bold mb-1">Town Hall Meeting</h3>
                    <p class="text-xs opacity-70 leading-relaxed">
                        Join us in the main conference room or via Zoom.
                    </p>
                </div>
                <div
                    class="relative pl-6 before:absolute before:left-0 before:top-0 before:bottom-0 before:w-1 before:bg-brand-paragraph before:rounded-full"
                >
                    <p class="text-[10px] font-black text-brand-bg uppercase mb-1">
                        Tomorrow
                    </p>
                    <h3 class="text-sm font-bold mb-1">Pantry Restock</h3>
                    <p class="text-xs opacity-70 leading-relaxed">
                        Fresh fruits and healthy snacks arriving early.
                    </p>
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
                    {{ new Date().toLocaleString('default', {month: 'long'}) }} Birthday Celebrants
                </h2>
            </div>

            <div
                class="space-y-4 grow custom-scrollbar overflow-y-auto pr-2 text-brand-stroke"
            >
                <div
                    v-for="(celebrant, celebrantsIndex) in props.currentMonthBirthdayCelebrants"
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
                        <p class="text-xs opacity-70">Marketing Dept</p>
                    </div>
                </div>
                <div
                    class="mt-6 p-4 bg-brand-accent/20 rounded-2xl text-center border-2 border-dashed"
                >
                    <p class="text-sm font-medium italic text-brand-bg">
                        "Enjoy your special day!" 🎂
                    </p>
                </div>
            </div>
        </div>
    </aside>
</template>

<style scoped>

</style>
