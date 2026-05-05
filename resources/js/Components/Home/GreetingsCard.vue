<script setup lang="ts">

</script>

<template>
    <div
        id="greeting-card"
        class="bg-brand-card rounded-4xl p-6 border-2 border-brand-stroke shadow-[6px_6px_0px_0px_#001e1d] animate-fade-up"
    >
        <div class="flex items-center gap-4">
            <!-- Icon -->
            <div
                id="greeting-icon"
                class="w-12 h-12 flex items-center justify-center rounded-xl border border-brand-stroke bg-white text-xl"
            >
                👋
            </div>

            <!-- Text -->
            <div>
                <p id="greeting-text" class="text-lg font-bold text-brand-stroke">
                    Good morning
                </p>
                <p
                    id="greeting-subtext"
                    class="text-xs text-brand-bg opacity-70"
                >
                    Ready to clock in?
                </p>
            </div>
        </div>
    </div>
</template>

<style scoped>

</style>
