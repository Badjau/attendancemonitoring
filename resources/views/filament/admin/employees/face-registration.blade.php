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

    @include('filament.admin.employees.face-summary', ['employee' => $employee])

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-gray-950 shadow-sm dark:border-gray-700">
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
                    class="h-full w-full object-cover"
                    :class="isCameraReady && ! isReviewingCapture ? 'opacity-100' : 'opacity-0'"
                ></video>

                <canvas
                    x-ref="overlay"
                    class="absolute inset-0 h-full w-full"
                    x-show="! isReviewingCapture"
                ></canvas>
                <div
                    x-show="! isReviewingCapture"
                    class="pointer-events-none absolute inset-0 bg-black/10 backdrop-blur-[30px]"
                    style="-webkit-mask: radial-gradient(ellipse 15% 38% at center, transparent 98%, #000 100%); mask: radial-gradient(ellipse 15% 38% at center, transparent 98%, #000 100%);"
                ></div>
                <div
                    x-show="! isReviewingCapture"
                    class="pointer-events-none absolute left-1/2 top-1/2 h-[76%] w-[30%] -translate-x-1/2 -translate-y-1/2 rounded-[50%] border-4 bg-transparent transition"
                    :class="ovalStatusClass"
                ></div>

                <div
                    x-show="isReviewingCapture"
                    x-transition.opacity
                    class="absolute inset-0 flex flex-col bg-gray-950"
                >
                    <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-success-300">
                                Captured
                            </div>
                            <div class="text-sm font-semibold text-white">
                                Review this photo before saving
                            </div>
                        </div>
                        <div class="rounded-full bg-success-500/15 px-3 py-1 text-xs font-semibold text-success-200">
                            Clear face
                        </div>
                    </div>

                    <div class="flex min-h-0 flex-1 items-center justify-center p-6">
                        <img
                            :src="capturedPreview"
                            alt="Captured face preview"
                            class="max-h-full max-w-full rounded-2xl border border-white/15 bg-white object-contain shadow-2xl"
                        >
                    </div>

                    <div class="border-t border-white/10 bg-black/30 px-5 py-3 text-center text-sm font-medium text-white/80">
                        Save this image if the face is clear, centered, and unobstructed.
                    </div>
                </div>
                <canvas x-ref="captureCanvas" class="hidden"></canvas>
            </div>
        </div>

        <div class="space-y-4">
            <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Status</div>
                <div class="mt-1 font-medium text-gray-950 dark:text-white" x-text="statusText"></div>
                <div class="mt-2 text-xs font-medium text-warning-600 dark:text-warning-400">
                    Remove eyeglasses, shades, masks, or any object covering the face before saving.
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-2xl font-bold text-gray-950 dark:text-white" x-text="faceCount"></div>
                    <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Faces</div>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div
                        class="text-2xl font-bold"
                        :class="faceClear ? 'text-success-600 dark:text-success-400' : 'text-gray-950 dark:text-white'"
                        x-text="faceClear ? 'Clear' : 'Check'"
                    ></div>
                    <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Face</div>
                </div>
            </div>

            <template x-if="message">
                <div
                    class="rounded-lg border p-4 text-sm"
                    :class="success ? 'border-success-200 bg-success-50 text-success-700 dark:border-success-800 dark:bg-success-950 dark:text-success-300' : 'border-warning-200 bg-warning-50 text-warning-700 dark:border-warning-800 dark:bg-warning-950 dark:text-warning-300'"
                    x-text="message"
                ></div>
            </template>

            <div
                x-show="isReviewingCapture"
                x-transition.opacity
                class="rounded-xl border border-success-200 bg-success-50 p-4 text-sm text-success-800 dark:border-success-800 dark:bg-success-950 dark:text-success-200"
            >
                <div class="font-semibold">Ready to save?</div>
                <div class="mt-1 text-xs">
                    Choose retake if the photo is blurry, cropped badly, or the employee is not looking straight.
                </div>
            </div>

            <div x-show="isReviewingCapture" x-transition.opacity class="grid grid-cols-2 gap-3">
                <button
                    type="button"
                    class="fi-btn fi-btn-size-lg fi-color-gray w-full"
                    :disabled="isSubmitting"
                    @click="retake"
                >
                    Retake photo
                </button>

                <button
                    type="button"
                    class="fi-btn fi-btn-size-lg fi-color-primary w-full"
                    :disabled="! canSave"
                    @click="save"
                >
                    <span x-show="! isSubmitting">Save this photo</span>
                    <span x-show="isSubmitting">Saving...</span>
                </button>
            </div>
        </div>
    </div>
</div>
