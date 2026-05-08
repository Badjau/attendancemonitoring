<div
    class="space-y-4"
    x-data="fingerprintEnrollment({
        optionsUrl: @js(route('admin.employees.fingerprint.options', $employee)),
        registerUrl: @js(route('admin.employees.fingerprint.register', $employee)),
    })"
>
    <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        <div class="font-semibold text-gray-950 dark:text-white">{{ $employee->name }}</div>
        <div class="mt-1 text-gray-500 dark:text-gray-400">{{ $employee->employee_id }} &middot; {{ $employee->position }}</div>
    </div>

    <template x-if="! supported">
        <div class="rounded-lg border border-danger-200 bg-danger-50 p-4 text-sm text-danger-700 dark:border-danger-800 dark:bg-danger-950 dark:text-danger-300">
            This browser does not support WebAuthn or biometric credentials.
        </div>
    </template>

    <template x-if="message">
        <div
            class="rounded-lg border p-4 text-sm"
            :class="success ? 'border-success-200 bg-success-50 text-success-700 dark:border-success-800 dark:bg-success-950 dark:text-success-300' : 'border-warning-200 bg-warning-50 text-warning-700 dark:border-warning-800 dark:bg-warning-950 dark:text-warning-300'"
            x-text="message"
        ></div>
    </template>

    <button
        type="button"
        class="fi-btn fi-btn-size-md fi-color-primary"
        :disabled="loading || ! supported"
        @click="enroll"
    >
        <span x-show="! loading">Start fingerprint enrollment</span>
        <span x-show="loading">Waiting for browser verification...</span>
    </button>
</div>
