<div
    class="space-y-4"
    x-data="fingerprintEnrollment({
        optionsUrl: @js(route('admin.employees.fingerprint.options', $employee)),
        registerUrl: @js(route('admin.employees.fingerprint.register', $employee)),
        deleteUrl: @js(route('admin.employees.fingerprint.destroy-finger', $employee)),
        zktecoBridgeUrl: @js(config('services.zkteco.bridge_url')),
        employee: @js([
            'id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'name' => $employee->name,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'position' => $employee->position,
        ]),
        registeredFingers: @js($employee->webAuthnCredentials()
            ->pluck('alias')
            ->filter()
            ->map(fn (string $alias): ?string => str($alias)->before(' fingerprint - scan')->toString())
            ->filter()
            ->unique()
            ->values()),
    })"
>
    <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        <div class="font-semibold text-gray-950 dark:text-white">{{ $employee->name }}</div>
        <div class="mt-1 text-gray-500 dark:text-gray-400">{{ $employee->employee_id }} &middot; {{ $employee->position }}</div>
    </div>

    @include('filament.admin.employees.fingerprint-summary', ['employee' => $employee])

    <div class="rounded-lg border border-primary-200 bg-primary-50 p-4 text-sm text-primary-800 dark:border-primary-800 dark:bg-primary-950 dark:text-primary-200">
        <div class="font-semibold">ZKTeco scanner enrollment</div>
        <div class="mt-1 text-primary-700 dark:text-primary-300">
            Keep the ZKTeco Bridge app open, then start scanner enrollment for this employee.
        </div>

        <button
            type="button"
            class="fi-btn fi-btn-size-md fi-color-primary mt-3"
            :disabled="zktecoLoading"
            @click="startZktecoEnrollment"
        >
            <span x-show="! zktecoLoading">Start ZKTeco enrollment</span>
            <span x-show="zktecoLoading">Connecting to bridge...</span>
        </button>
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

    <div class="space-y-3">
        <div class="text-sm font-semibold text-gray-950 dark:text-white">Select finger</div>

        <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
            <template x-for="finger in fingers" :key="finger.value">
                <button
                    type="button"
                    class="rounded-lg border px-3 py-2 text-left text-sm font-medium transition"
                    :class="selectedFinger === finger.value
                        ? 'border-primary-500 bg-primary-50 text-primary-700 dark:border-primary-400 dark:bg-primary-950 dark:text-primary-300'
                        : isRegistered(finger)
                            ? 'border-warning-300 bg-warning-50 text-warning-700 dark:border-warning-800 dark:bg-warning-950 dark:text-warning-300'
                            : 'border-gray-200 bg-white text-gray-700 hover:border-primary-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200'"
                    :disabled="loading"
                    @click="selectFinger(finger)"
                >
                    <span x-text="finger.label"></span>
                    <span x-show="isRegistered(finger)" class="mt-1 block text-xs font-normal">Registered</span>
                </button>
            </template>
        </div>
    </div>

    <div class="rounded-xl border border-gray-200 bg-gray-50 p-5 dark:border-gray-700 dark:bg-gray-900">
        <div class="flex items-center justify-center gap-4">
            <template x-for="tap in requiredTaps" :key="tap">
                <div class="flex flex-col items-center gap-2">
                    <div
                        class="relative flex h-16 w-16 items-center justify-center rounded-full border-2 text-lg font-bold transition"
                        :class="completedTaps >= tap
                            ? 'border-success-500 bg-success-100 text-success-700 dark:bg-success-950 dark:text-success-300'
                            : activeTap === tap
                                ? 'border-primary-500 bg-primary-100 text-primary-700 dark:bg-primary-950 dark:text-primary-300'
                                : 'border-gray-300 bg-white text-gray-400 dark:border-gray-700 dark:bg-gray-950'"
                    >
                        <span
                            x-show="activeTap === tap && loading"
                            class="absolute inset-0 rounded-full border-2 border-primary-400 opacity-70 animate-ping"
                        ></span>
                        <span x-show="completedTaps < tap" x-text="tap"></span>
                        <span x-show="completedTaps >= tap">OK</span>
                    </div>
                    <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Tap <span x-text="tap"></span></div>
                </div>
            </template>
        </div>
    </div>

    <button
        type="button"
        class="fi-btn fi-btn-size-md fi-color-primary"
        :disabled="! canEnroll"
        @click="enroll"
    >
        <span x-show="! loading && completedTaps === 0">Start tap 1 of 3</span>
        <span x-show="! loading && completedTaps > 0 && completedTaps < requiredTaps">
            Continue tap <span x-text="completedTaps + 1"></span> of 3
        </span>
        <span x-show="! loading && completedTaps === requiredTaps">Fingerprint enrolled</span>
        <span x-show="loading">Reading fingerprint...</span>
    </button>

    <button
        type="button"
        class="fi-btn fi-btn-size-md fi-color-danger"
        x-show="selectedFingerRegistered"
        :disabled="loading"
        @click="removeSelectedFinger"
    >
        Remove registered finger
    </button>
</div>
