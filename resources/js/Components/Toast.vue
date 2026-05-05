<script setup lang="ts">
import {LogIn, LogOut} from '@lucide/vue'
import {useToast} from "@/Composables/useToast";

const {toasts} = useToast()
</script>

<template>
    <div class="fixed top-6 right-6 space-y-3 z-50">
        <div
            v-for="toast in toasts"
            :key="toast.id"
            class="animate-fade-up flex items-center gap-4 px-6 py-4 rounded-2xl border-2 border-brand-stroke shadow-[6px_6px_0px_0px_#001e1d]"
            :class="toast.type === 'in'
        ? 'bg-brand-accent text-brand-stroke'
        : 'bg-brand-tertiary text-brand-headline'"
        >
            <div class="p-2 bg-white/30 rounded-lg">
                <LogIn v-if="toast.type === 'in'" class="w-5 h-5"/>
                <LogOut v-else class="w-5 h-5"/>
            </div>

            <div>
                <p class="font-black text-sm uppercase tracking-tight">
                    {{ toast.message }}
                </p>
                <p v-if="toast.time" class="text-xs font-bold opacity-80">
                    {{ toast.time }}
                </p>
            </div>
        </div>
    </div>
</template>
