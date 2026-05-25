@php
    $registeredFingers = $employee->webAuthnCredentials()
        ->pluck('alias')
        ->filter()
        ->map(fn (string $alias): string => str($alias)->before(' fingerprint - scan')->toString())
        ->filter()
        ->unique()
        ->values();

    $zktecoTemplates = $employee->zktecoFingerprintTemplates()
        ->latest('enrolled_at')
        ->get();
@endphp

<div class="space-y-3 rounded-lg border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-900">
    <div>
        <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Browser fingerprint</div>

        @if ($registeredFingers->isNotEmpty())
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($registeredFingers as $finger)
                    <span class="rounded-full border border-success-200 bg-success-50 px-3 py-1 text-xs font-semibold text-success-700 dark:border-success-800 dark:bg-success-950 dark:text-success-300">
                        {{ $finger }}
                    </span>
                @endforeach
            </div>
        @else
            <div class="mt-2 font-medium text-gray-950 dark:text-white">No browser fingerprint registered yet.</div>
        @endif
    </div>

    <div>
        <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">ZKTeco scanner fingerprint</div>

        @if ($zktecoTemplates->isNotEmpty())
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($zktecoTemplates as $template)
                    <span class="rounded-full border border-success-200 bg-success-50 px-3 py-1 text-xs font-semibold text-success-700 dark:border-success-800 dark:bg-success-950 dark:text-success-300">
                        Finger {{ $template->finger_index }}{{ $template->enrolled_at ? ' · '.$template->enrolled_at->format('M d, Y h:i A') : '' }}
                    </span>
                @endforeach
            </div>
        @else
            <div class="mt-2 font-medium text-gray-950 dark:text-white">No ZKTeco scanner fingerprint registered yet.</div>
        @endif
    </div>
</div>
