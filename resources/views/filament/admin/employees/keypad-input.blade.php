<div
    class="max-w-xs rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
    x-data="{
        state: $wire.entangle(@js($statePath)),
        press(value) {
            this.state = `${this.state ?? ''}${value}`;
        },
        clear() {
            this.state = '';
        },
        backspace() {
            this.state = `${this.state ?? ''}`.slice(0, -1);
        },
    }"
>
    <div class="grid grid-cols-3 gap-2">
        @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $digit)
            <button
                type="button"
                class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-lg font-semibold text-gray-950 transition hover:border-primary-300 hover:bg-primary-50 dark:border-gray-700 dark:bg-gray-950 dark:text-white dark:hover:border-primary-500 dark:hover:bg-primary-950"
                @click="press('{{ $digit }}')"
            >
                {{ $digit }}
            </button>
        @endforeach

        <button
            type="button"
            class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-warning-300 hover:bg-warning-50 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200 dark:hover:border-warning-500 dark:hover:bg-warning-950"
            @click="clear"
        >
            Clear
        </button>

        <button
            type="button"
            class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-lg font-semibold text-gray-950 transition hover:border-primary-300 hover:bg-primary-50 dark:border-gray-700 dark:bg-gray-950 dark:text-white dark:hover:border-primary-500 dark:hover:bg-primary-950"
            @click="press('0')"
        >
            0
        </button>

        <button
            type="button"
            class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-warning-300 hover:bg-warning-50 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200 dark:hover:border-warning-500 dark:hover:bg-warning-950"
            @click="backspace"
        >
            Delete
        </button>
    </div>
</div>
