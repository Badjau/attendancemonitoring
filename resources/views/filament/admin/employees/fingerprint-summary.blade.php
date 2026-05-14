@php
    $registeredFingers = $employee->webAuthnCredentials()
        ->pluck('alias')
        ->filter()
        ->map(fn (string $alias): string => str($alias)->before(' fingerprint - scan')->toString())
        ->filter()
        ->unique()
        ->values();
@endphp

<div class="rounded-lg border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-900">
    <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Registered fingerprint</div>

    @if ($registeredFingers->isNotEmpty())
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ($registeredFingers as $finger)
                <span class="rounded-full border border-success-200 bg-success-50 px-3 py-1 text-xs font-semibold text-success-700 dark:border-success-800 dark:bg-success-950 dark:text-success-300">
                    {{ $finger }}
                </span>
            @endforeach
        </div>
    @else
        <div class="mt-2 font-medium text-gray-950 dark:text-white">No fingerprint registered yet.</div>
    @endif
</div>
