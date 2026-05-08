<script setup lang="ts">
import {Fingerprint, LogIn, LogOut} from '@lucide/vue';
import {nextTick, onMounted, onUnmounted, ref, watch} from 'vue';

type AttendanceAction = 'time-in' | 'time-out'

const props = defineProps<{
    currentDate: string
    currentTime: string
    faceStatusText: string
    isLoading: boolean
    showEmployeeIdInputField: boolean
}>();

const emit = defineEmits<{
    fingerprint: []
    manualSubmit: [employeeId: string]
    rfidScan: [rfid: string]
    timeAction: [action: AttendanceAction]
}>();

const rfidInput = ref<HTMLInputElement | null>(null);
const empIdInput = ref<HTMLInputElement | null>(null);
const rfidBuffer = ref('');
const manualEmployeeId = ref('');

let focusInterval: ReturnType<typeof setInterval> | null = null;
let rfidTimeout: ReturnType<typeof setTimeout> | null = null;

const clearManualEmployeeId = () => {
    manualEmployeeId.value = '';
};

const clearRFIDInput = () => {
    rfidBuffer.value = '';
    if (rfidInput.value) rfidInput.value.value = '';
};

const focusRFID = () => {
    if (!rfidInput.value || document.activeElement === empIdInput.value) return;

    try {
        rfidInput.value.focus();
    } catch (error) {
        console.error('Error focusing RFID input:', error);
    }
};

const ensureRFIDFocus = () => {
    if (document.activeElement === empIdInput.value) return;

    try {
        if (rfidInput.value && document.activeElement !== rfidInput.value) {
            rfidInput.value.focus();
        }
    } catch {
        // Browser focus can fail when the element is temporarily unavailable.
    }
};

const forceRFIDFocus = () => {
    try {
        rfidInput.value?.focus?.();
    } catch {
        // Browser focus can fail when the element is temporarily unavailable.
    }
};

const submitRFID = (rfid?: string) => {
    const scannedRfid = rfid?.trim();
    clearRFIDInput();
    setTimeout(() => ensureRFIDFocus(), 50);

    if (scannedRfid) emit('rfidScan', scannedRfid);
};

const onRFIDInput = () => {
    const data = rfidInput.value?.value.trim();
    if (!data) return;

    rfidBuffer.value = data;

    if (rfidTimeout) clearTimeout(rfidTimeout);
    rfidTimeout = setTimeout(() => submitRFID(rfidBuffer.value), 100);
};

const onRFIDKeydown = (event: KeyboardEvent) => {
    if (event.key !== 'Enter') return;

    event.preventDefault();
    if (rfidTimeout) clearTimeout(rfidTimeout);
    submitRFID(rfidBuffer.value || rfidInput.value?.value);
};

const onEmpIdFocus = (event: FocusEvent) => {
    const input = event.target as HTMLInputElement | null;
    input?.select?.();
};

const onEmpIdKeydown = (event: KeyboardEvent) => {
    if (event.key !== 'Enter') return;

    event.preventDefault();
    submitManualAttendance();
};

const submitManualAttendance = () => {
    emit('manualSubmit', manualEmployeeId.value.trim());
};

const onDocumentClick = (event: MouseEvent | TouchEvent) => {
    if (event.target === empIdInput.value) return;
    focusRFID();
};

onMounted(() => {
    ensureRFIDFocus();
    focusInterval = setInterval(ensureRFIDFocus, 300);
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('touchend', onDocumentClick);
});

onUnmounted(() => {
    if (focusInterval) clearInterval(focusInterval);
    if (rfidTimeout) clearTimeout(rfidTimeout);
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('touchend', onDocumentClick);
});

watch(
    () => props.showEmployeeIdInputField,
    async (showInput) => {
        if (!showInput) return;

        await nextTick();
        forceRFIDFocus();
    },
);

defineExpose({
    clearManualEmployeeId,
    forceRFIDFocus,
});
</script>

<template>
    <div class="flex flex-col gap-8">
        <div
            class="bg-brand-card rounded-4xl p-8 shadow-[8px_8px_0px_0px_#001e1d] border-2 border-brand-stroke shrink-0 animate-fade-up"
        >
            <div class="flex flex-col items-center text-center space-y-1 mb-8">
                <p class="text-brand-bg font-bold tracking-wider uppercase text-xs">
                    {{ currentDate }}
                </p>
                <h1 class="text-4xl lg:text-5xl font-black tracking-tight text-brand-stroke tabular-nums">
                    {{ currentTime }}
                </h1>
            </div>

            <div class="w-full">
                <div class="grid grid-cols-2 gap-4">
                    <button
                        class="group relative bg-brand-accent hover:bg-[#ffcf81] text-brand-stroke border-2 border-brand-stroke rounded-2xl py-4 px-3 transition-all font-bold shadow-[4px_4px_0px_0px_#001e1d] active:translate-x-1 active:translate-y-1 active:shadow-none flex flex-col items-center gap-2"
                        @click="emit('timeAction', 'time-in')"
                    >
                        <LogIn class="w-5 h-5"/>
                        <span class="text-sm">Time In</span>
                    </button>

                    <button
                        class="group relative bg-brand-tertiary hover:bg-[#f07a7b] text-brand-headline border-2 border-brand-stroke rounded-2xl py-4 px-3 transition-all font-bold shadow-[4px_4px_0px_0px_#001e1d] active:translate-x-1 active:translate-y-1 active:shadow-none flex flex-col items-center gap-2"
                        @click="emit('timeAction', 'time-out')"
                    >
                        <LogOut class="w-5 h-5"/>
                        <span class="text-sm">Time Out</span>
                    </button>
                </div>

                <button
                    type="button"
                    class="mt-4 inline-flex w-full items-center justify-center gap-2 bg-brand-card text-brand-stroke border-2 border-brand-stroke rounded-2xl py-4 px-3 transition-all font-bold shadow-[4px_4px_0px_0px_#001e1d] active:translate-x-1 active:translate-y-1 active:shadow-none"
                    title="Fingerprint"
                    @click="emit('fingerprint')"
                >
                    <Fingerprint class="w-5 h-5"/>
                    <span class="text-sm">Fingerprint</span>
                </button>

                <div v-if="isLoading" class="mt-5">
                    <p class="text-brand-bg font-bold tracking-wider uppercase text-xs">
                        Processing, please wait...
                    </p>
                </div>

                <div v-else class="flex flex-col justify-center gap-3">
                    <div class="w-full">
                        <input
                            ref="rfidInput"
                            type="text"
                            autocomplete="off"
                            class="absolute -top-96"
                            style="opacity: 0; pointer-events: none;"
                            @input="onRFIDInput"
                            @keydown="onRFIDKeydown"
                        />

                        <div class="flex items-center justify-center gap-2">
                            <input
                                v-if="showEmployeeIdInputField"
                                ref="empIdInput"
                                v-model="manualEmployeeId"
                                type="text"
                                placeholder="Employee ID"
                                class="text-brand-stroke border-2 border-brand-stroke rounded-xl py-3 px-3 text-sm w-full mt-4"
                                @focus="onEmpIdFocus"
                                @keydown="onEmpIdKeydown"
                            />

                            <div v-if="showEmployeeIdInputField" class="mt-3">
                                <button
                                    type="button"
                                    class="w-full bg-brand-stroke text-brand-headline border-2 border-brand-stroke rounded-xl py-3 px-4 text-sm font-bold shadow-[3px_3px_0px_0px_#abd1c6] active:translate-x-1 active:translate-y-1 active:shadow-none"
                                    @click="submitManualAttendance"
                                >
                                    Submit
                                </button>
                            </div>
                        </div>
                    </div>

                    <p
                        v-if="showEmployeeIdInputField || faceStatusText !== 'Face verification ready.'"
                        class="text-brand-bg text-xs font-black uppercase tracking-wide"
                    >
                        {{ faceStatusText }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
