<script setup lang="ts">
import { Toast } from 'primevue'
</script>

<template>
    <Toast
        position="top-right"
        :pt="{
            root: {
                class: 'flex flex-col gap-3 w-[20rem] max-w-[calc(100vw-2rem)]',
            },
            message: ({ props }) => ({
                class: [
                    'relative w-full overflow-hidden rounded-2xl border-2 border-brand-stroke shadow-[6px_6px_0px_0px_#001e1d] animate-fade-up',
                    {
                        'bg-brand-accent text-brand-stroke':
                            props.message.severity === 'success',
                        'bg-brand-tertiary text-brand-headline':
                            props.message.severity === 'error',
                        'bg-blue-100 text-blue-800':
                            props.message.severity === 'info',
                        'bg-yellow-100 text-yellow-800':
                            props.message.severity === 'warn',
                    },
                ],
            }),
            messageContent: { class: 'flex items-start gap-3 px-5 py-4' },
            messageIcon: { class: 'mt-0.5 w-5 h-5 shrink-0' },
            messageText: { class: 'min-w-0 flex-1' },
            summary: {
                class: 'block font-black text-sm uppercase leading-tight tracking-normal break-words',
            },
            detail: {
                class: 'mt-1 text-xs font-semibold leading-snug opacity-80 break-words',
            },
            buttonContainer: { class: 'ml-auto shrink-0 self-start' },
            closeButton: {
                class: 'inline-flex h-7 w-7 items-center justify-center rounded-lg opacity-60 hover:opacity-100 transition-opacity',
            },
            closeIcon: { class: 'w-4 h-4' },
        }"
    />
</template>
