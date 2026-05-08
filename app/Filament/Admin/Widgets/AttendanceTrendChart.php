<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Attendance;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Carbon;

class AttendanceTrendChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = [
        'md' => 2,
        'xl' => 2,
    ];

    protected ?string $heading = 'Attendance trend';

    protected ?string $description = 'Clock-ins, late arrivals, and overtime records from the last 7 days.';

    protected string $color = 'primary';

    public function updatedPageFilters(): void
    {
        $this->cachedData = null;
    }

    protected function getData(): array
    {
        $endDate = Carbon::parse($this->pageFilters['date'] ?? today());
        $startDate = $endDate->copy()->subDays(6);

        $records = Attendance::query()
            ->selectRaw('attendance_date')
            ->selectRaw('count(case when time_in is not null then 1 end) as clock_ins')
            ->selectRaw('sum(case when is_late = 1 then 1 else 0 end) as late_count')
            ->selectRaw('sum(case when is_overtime = 1 then 1 else 0 end) as overtime_count')
            ->whereBetween('attendance_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('attendance_date')
            ->get()
            ->keyBy(fn (Attendance $record): string => $record->attendance_date->toDateString());

        $days = collect(range(0, 6))
            ->map(fn (int $offset) => $startDate->copy()->addDays($offset));

        return [
            'datasets' => [
                [
                    'label' => 'Clock-ins',
                    'data' => $days
                        ->map(fn ($date): int => (int) ($records[$date->toDateString()]->clock_ins ?? 0))
                        ->all(),
                    'borderColor' => '#004643',
                    'backgroundColor' => 'rgba(0, 70, 67, 0.15)',
                    'tension' => 0.35,
                ],
                [
                    'label' => 'Late',
                    'data' => $days
                        ->map(fn ($date): int => (int) ($records[$date->toDateString()]->late_count ?? 0))
                        ->all(),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.12)',
                    'tension' => 0.35,
                ],
                [
                    'label' => 'Overtime',
                    'data' => $days
                        ->map(fn ($date): int => (int) ($records[$date->toDateString()]->overtime_count ?? 0))
                        ->all(),
                    'borderColor' => '#0ea5e9',
                    'backgroundColor' => 'rgba(14, 165, 233, 0.12)',
                    'tension' => 0.35,
                ],
            ],
            'labels' => $days
                ->map(fn ($date): string => $date->format('M j'))
                ->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
