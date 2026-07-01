@php($profileUrl = $employee->employeeProfileUrl())

<div class="rounded-lg border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-900">
    <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Registered face</div>

    <div class="mt-3 flex items-center gap-3">
        @if (filled($profileUrl))
            <img
                src="{{ $profileUrl }}"
                alt="{{ $employee->name }}"
                class="h-16 w-16 rounded-full border border-gray-200 object-cover dark:border-gray-700"
            />
        @endif

        <div class="min-w-0">
            <div class="truncate font-semibold text-gray-950 dark:text-white">{{ $employee->name }}</div>
            <div class="truncate text-xs text-gray-500 dark:text-gray-400">
                {{ filled($profileUrl) ? 'Registered face exists' : 'No face registered yet' }}
            </div>
        </div>
    </div>
</div>
