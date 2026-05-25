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

    $templates = $employee->zktecoFingerprintTemplates()
        ->latest('enrolled_at')
        ->get();
@endphp

<div class="space-y-3 rounded-lg border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-900">
    <div>
        <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Registered fingerprint</div>

        @if ($templates->isNotEmpty())
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($templates as $template)
                    <span class="rounded-full border border-success-200 bg-success-50 px-3 py-1 text-xs font-semibold text-success-700 dark:border-success-800 dark:bg-success-950 dark:text-success-300">
                        {{ $fingerLabels[(int) $template->finger_index] ?? 'Finger '.$template->finger_index }}@if ($template->enrolled_at) &middot; {{ $template->enrolled_at->format('M d, Y h:i A') }} @endif
                    </span>
                @endforeach
            </div>
        @else
            <div class="mt-2 font-medium text-gray-950 dark:text-white">No fingerprint registered yet.</div>
        @endif
    </div>
</div>
