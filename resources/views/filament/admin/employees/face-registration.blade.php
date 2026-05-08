<div
    class="space-y-4"
    x-data="faceRegistration({
        registerUrl: @js(route('admin.employees.face.register', $employee)),
        existingFaces: @js(\App\Models\Employee::with('media')
            ->whereKeyNot($employee->getKey())
            ->get()
            ->map(fn (\App\Models\Employee $item): array => [
                'employee_id' => $item->employee_id,
                'name' => $item->name,
                'profile_url' => $item->getFirstMediaUrl('employee-profile'),
            ])
            ->filter(fn (array $item): bool => filled($item['profile_url']))
            ->values()),
    })"
>
    <div
        class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        <div class="font-semibold text-gray-950 dark:text-white">{{ $employee->name }}</div>
        <div class="mt-1 text-gray-500 dark:text-gray-400">
            {{ $employee->employee_id }} &middot; {{ $employee->position }}</div>
    </div>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-gray-950 dark:border-gray-700">
            <div class="relative aspect-video">
                <div
                    x-show="! isCameraReady"
                    class="absolute inset-0 z-10 flex items-center justify-center bg-gray-950 px-4 text-center text-sm font-semibold text-white"
                    x-text="statusText"
                ></div>

                <video
                    x-ref="video"
                    autoplay
                    muted
                    playsinline
                    class="h-full w-full scale-x-[-1] object-cover"
                    :class="isCameraReady ? 'opacity-100' : 'opacity-0'"
                ></video>

                <canvas x-ref="overlay" class="absolute inset-0 h-full w-full scale-x-[-1]"></canvas>
                <canvas x-ref="captureCanvas" class="hidden"></canvas>
            </div>
        </div>

        <div class="space-y-4">
            <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Status</div>
                <div class="mt-1 font-medium text-gray-950 dark:text-white" x-text="statusText"></div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-2xl font-bold text-gray-950 dark:text-white" x-text="faceCount"></div>
                    <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Faces</div>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-2xl font-bold text-gray-950 dark:text-white"
                         x-text="isModelReady ? 'Ready' : 'Load'"></div>
                    <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Model</div>
                </div>
            </div>

            <div
                class="flex items-center gap-3 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                @php($profileUrl = $employee->getFirstMediaUrl('employee-profile'))
                <img
                    x-show="capturedPreview || @js(filled($profileUrl))"
                    :src="capturedPreview || @js($profileUrl)"
                    alt="{{ $employee->name }}"
                    class="h-14 w-14 rounded-full border border-gray-200 object-cover dark:border-gray-700"
                />
                <div class="min-w-0">
                    <div class="truncate font-semibold text-gray-950 dark:text-white">{{ $employee->name }}</div>
                    <div class="truncate text-xs text-gray-500 dark:text-gray-400">
                        {{ filled($profileUrl) ? 'Registered face exists' : 'No face registered yet' }}
                    </div>
                </div>
            </div>

            <template x-if="message">
                <div
                    class="rounded-lg border p-4 text-sm"
                    :class="success ? 'border-success-200 bg-success-50 text-success-700 dark:border-success-800 dark:bg-success-950 dark:text-success-300' : 'border-warning-200 bg-warning-50 text-warning-700 dark:border-warning-800 dark:bg-warning-950 dark:text-warning-300'"
                    x-text="message"
                ></div>
            </template>

            <button
                type="button"
                class="fi-btn fi-btn-size-md fi-color-primary w-full"
                :disabled="! canSave"
                @click="save"
            >
                <span x-show="! isSubmitting">Save face</span>
                <span x-show="isSubmitting">Saving...</span>
            </button>
        </div>
    </div>
</div>
