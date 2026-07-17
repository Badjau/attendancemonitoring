@php($breaks = collect($getState()['breaks'] ?? []))

@if ($breaks->isEmpty())
    <div class="text-sm text-gray-500 dark:text-gray-400">No breaks recorded.</div>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                <tr>
                    <th class="px-3 py-2">#</th>
                    <th class="px-3 py-2">Type</th>
                    <th class="px-3 py-2">Allowed</th>
                    <th class="px-3 py-2">Started</th>
                    <th class="px-3 py-2">Start Photos</th>
                    <th class="px-3 py-2">Ended</th>
                    <th class="px-3 py-2">End Photos</th>
                    <th class="px-3 py-2">Duration</th>
                    <th class="px-3 py-2">Overage</th>
                    <th class="px-3 py-2">Closed By</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($breaks as $break)
                    <tr>
                        <td class="px-3 py-2">{{ $break->sequence_number }}</td>
                        <td class="px-3 py-2">{{ str($break->break_policy_type)->headline() }}</td>
                        <td class="px-3 py-2">{{ $break->allowed_minutes }} min</td>
                        <td class="px-3 py-2">{{ $break->started_at?->format('M d, Y h:i A') }}</td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap gap-2">
                                @forelse ($break->getMedia('break-start-images') as $media)
                                    <a href="{{ $media->getUrl() }}" target="_blank" rel="noopener noreferrer">
                                        <img
                                            src="{{ $media->getUrl() }}"
                                            alt="Break start photo {{ $loop->iteration }}"
                                            class="h-16 w-16 rounded-md object-cover ring-1 ring-gray-200 dark:ring-white/10"
                                        >
                                    </a>
                                @empty
                                    <span class="text-gray-500 dark:text-gray-400">-</span>
                                @endforelse
                            </div>
                        </td>
                        <td class="px-3 py-2">{{ $break->ended_at?->format('M d, Y h:i A') ?? '-' }}</td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap gap-2">
                                @forelse ($break->getMedia('break-end-images') as $media)
                                    <a href="{{ $media->getUrl() }}" target="_blank" rel="noopener noreferrer">
                                        <img
                                            src="{{ $media->getUrl() }}"
                                            alt="Break end photo {{ $loop->iteration }}"
                                            class="h-16 w-16 rounded-md object-cover ring-1 ring-gray-200 dark:ring-white/10"
                                        >
                                    </a>
                                @empty
                                    <span class="text-gray-500 dark:text-gray-400">-</span>
                                @endforelse
                            </div>
                        </td>
                        <td class="px-3 py-2">{{ $break->duration_minutes }} min</td>
                        <td class="px-3 py-2">{{ $break->exceeded_minutes }} min</td>
                        <td class="px-3 py-2">{{ $break->closed_by_time_out ? 'Time Out' : '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
