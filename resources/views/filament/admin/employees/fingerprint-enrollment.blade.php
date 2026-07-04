@php
    $fingerLabels = [
        1 => 'Left Thumb',
        2 => 'Left Index',
        3 => 'Left Middle',
        4 => 'Left Ring',
        5 => 'Left Little',
        6 => 'Right Thumb',
        7 => 'Right Index',
        8 => 'Right Middle',
        9 => 'Right Ring',
        10 => 'Right Little',
    ];

    $registeredTemplates = $employee->zktecoFingerprintTemplates()
        ->latest('enrolled_at')
        ->get()
        ->map(fn ($template): array => [
            'id' => $template->id,
            'finger_index' => (int) $template->finger_index,
            'label' => $fingerLabels[(int) $template->finger_index] ?? 'Finger '.$template->finger_index,
            'enrolled_at' => $template->enrolled_at?->format('M d, Y h:i A'),
        ])
        ->values();
@endphp

<div
    class="space-y-5"
    x-data="fingerprintEnrollment({
        bridgeUrl: @js(config('services.zkteco.bridge_url')),
        scannerDeleteUrl: @js(route('admin.employees.fingerprint.destroy-scanner-finger', $employee)),
        employee: @js([
            'id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'name' => $employee->name,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'position' => $employee->position,
        ]),
        registeredTemplates: @js($registeredTemplates),
    })"
>
    <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        <div class="font-semibold text-gray-950 dark:text-white">{{ $employee->name }}</div>
        <div class="mt-1 text-gray-500 dark:text-gray-400">{{ $employee->employee_id }} &middot; {{ $employee->position }}</div>
    </div>

    <div class="space-y-3 rounded-lg border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex items-center justify-between gap-3">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Registered fingerprint</div>
            <div class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                <span x-text="registeredTemplates.length"></span>/3 registered
            </div>
        </div>

        <template x-if="registeredTemplates.length > 0">
            <div class="mt-3 flex flex-wrap gap-2">
                <template x-for="template in registeredTemplates" :key="template.finger_index">
                    <div class="flex items-center gap-2 rounded-full border border-success-200 bg-success-50 px-3 py-1 text-xs font-semibold text-success-700 dark:border-success-800 dark:bg-success-950 dark:text-success-300">
                        <span>
                            <span x-text="template.label"></span>
                            <template x-if="template.enrolled_at">
                                <span> &middot; <span x-text="template.enrolled_at"></span></span>
                            </template>
                        </span>
                        <button
                            type="button"
                            class="text-danger-600 hover:text-danger-700 disabled:opacity-60 dark:text-danger-400 dark:hover:text-danger-300"
                            :disabled="busy || removingFingerIndex === template.finger_index"
                            @click="removeRegisteredFinger(template)"
                        >
                            <span x-show="removingFingerIndex !== template.finger_index">Remove</span>
                            <span x-show="removingFingerIndex === template.finger_index">Removing...</span>
                        </button>
                    </div>
                </template>
            </div>
        </template>

        <template x-if="registeredTemplates.length === 0">
            <div class="mt-2 font-medium text-gray-950 dark:text-white">No fingerprint registered yet.</div>
        </template>
    </div>

    <template x-if="message">
        <div
            class="rounded-lg border p-4 text-sm"
            :class="success ? 'border-success-200 bg-success-50 text-success-700 dark:border-success-800 dark:bg-success-950 dark:text-success-300' : 'border-warning-200 bg-warning-50 text-warning-700 dark:border-warning-800 dark:bg-warning-950 dark:text-warning-300'"
            x-text="message"
        ></div>
    </template>

    <template x-if="registrationLimitReached">
        <div class="rounded-lg border border-warning-200 bg-warning-50 p-4 text-sm text-warning-700 dark:border-warning-800 dark:bg-warning-950 dark:text-warning-300">
            This employee already has 3 registered fingers. Remove one before registering another.
        </div>
    </template>

    <div class="space-y-3">
        <div class="text-sm font-semibold text-gray-950 dark:text-white">Select finger</div>

        <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
            <template x-for="finger in fingers" :key="finger.index">
                <button
                    type="button"
                    class="rounded-lg border px-3 py-2 text-left text-sm font-medium transition disabled:cursor-not-allowed"
                    :class="selectedFinger === finger.index
                        ? 'border-primary-500 bg-primary-50 text-primary-700 dark:border-primary-400 dark:bg-primary-950 dark:text-primary-300'
                        : isRegistered(finger)
                            ? 'border-warning-300 bg-warning-50 text-warning-700 dark:border-warning-800 dark:bg-warning-950 dark:text-warning-300'
                            : 'border-gray-200 bg-white text-gray-700 hover:border-primary-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200'"
                    :disabled="busy || isRegistered(finger) || registrationLimitReached"
                    @click="selectFinger(finger)"
                >
                    <span x-text="finger.label"></span>
                    <span x-show="isRegistered(finger)" class="mt-1 block text-xs font-normal">Registered</span>
                    <span x-show="selectedFinger === finger.index && ! isRegistered(finger)" class="mt-1 block text-xs font-normal">Selected</span>
                </button>
            </template>
        </div>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="mb-3 flex items-center justify-between gap-3">
            <div>
                <div class="text-sm font-semibold text-gray-950 dark:text-white">Fingerprint preview</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400" x-text="fingerprintPreviewImage ? 'Latest scan' : 'Waiting for scan'"></div>
            </div>
            <div
                class="rounded-full px-3 py-1 text-xs font-semibold"
                :class="fingerprintPreviewImage ? 'bg-success-50 text-success-700 dark:bg-success-950 dark:text-success-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'"
            >
                <span x-text="fingerprintPreviewImage ? 'Preview ready' : 'No image'"></span>
            </div>
        </div>

        <div class="flex aspect-[4/3] items-center justify-center overflow-hidden rounded-lg border border-dashed border-gray-300 bg-gray-50 dark:border-gray-700 dark:bg-gray-950">
            <template x-if="fingerprintPreviewImage">
                <img
                    :src="fingerprintPreviewImage"
                    alt="Latest fingerprint scan preview"
                    class="h-full w-full object-contain p-3"
                />
            </template>

            <template x-if="! fingerprintPreviewImage">
                <div class="px-4 text-center text-sm font-medium text-gray-500 dark:text-gray-400">
                    Scan a selected finger to show the latest fingerprint image here.
                </div>
            </template>
        </div>
    </div>

    <div class="flex flex-wrap gap-2">
        <button
            type="button"
            class="fi-btn fi-btn-size-md fi-color-primary"
            :disabled="! canScan"
            @click="startScannerEnrollment"
        >
            <span x-show="! zktecoLoading && ! enrollmentCaptured">Scan fingerprint</span>
            <span x-show="! zktecoLoading && enrollmentCaptured">Scan again</span>
            <span x-show="zktecoLoading">Reading fingerprint...</span>
        </button>

        <button
            type="button"
            class="fi-btn fi-btn-size-md fi-color-success"
            :disabled="! canSubmit"
            @click="submitEnrollment"
        >
            <span x-show="! submittingEnrollment">Save fingerprint</span>
            <span x-show="submittingEnrollment">Saving...</span>
        </button>
    </div>
</div>
