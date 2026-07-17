<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Attendance\OvertimeStatus;
use App\Models\Attendance;
use App\Models\Employee;
use Carbon\CarbonInterface;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class AttendanceStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected ?string $heading = 'Attendance at a glance';

    public function updatedPageFilters(): void
    {
        $this->cachedStats = null;
    }

    protected function getStats(): array
    {
        $date = $this->getSelectedDate();

        $summary = Attendance::query()
            ->whereDate('attendance_date', $date)
            ->selectRaw('count(case when time_in is not null then 1 end) as clocked_in')
            ->selectRaw('sum(case when is_late = 1 then 1 else 0 end) as late_count')
            ->selectRaw('sum(case when overtime_status = ? then 1 else 0 end) as pending_overtime_count', [OvertimeStatus::Pending->value])
            ->selectRaw('coalesce(sum(break_count), 0) as break_count')
            ->selectRaw('coalesce(sum(break_minutes), 0) as break_minutes')
            ->selectRaw('coalesce(sum(break_exceeded_minutes), 0) as break_exceeded_minutes')
            ->first();

        $clockedIn = (int) ($summary?->clocked_in ?? 0);
        $late = (int) ($summary?->late_count ?? 0);
        $pendingOvertime = (int) ($summary?->pending_overtime_count ?? 0);
        $breakCount = (int) ($summary?->break_count ?? 0);
        $breakMinutes = (int) ($summary?->break_minutes ?? 0);
        $breakExceededMinutes = (int) ($summary?->break_exceeded_minutes ?? 0);

        $employeeCount = Employee::query()->count();
        $presentRate = $employeeCount === 0
            ? 0
            : round(($clockedIn / $employeeCount) * 100);

        return [
            Stat::make('Employees clocked in', number_format($clockedIn))
                ->description("{$presentRate}% of {$employeeCount} employees")
                ->descriptionIcon(Heroicon::OutlinedArrowTrendingUp)
                ->color('success')
                ->icon(Heroicon::OutlinedUsers)
                ->chart($this->getDailyClockInTrend($date)),

            Stat::make('Late arrivals', number_format($late))
                ->description('Records marked late for selected date')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color($late > 0 ? 'warning' : 'success')
                ->icon(Heroicon::OutlinedExclamationTriangle),

            Stat::make('Pending overtime', number_format($pendingOvertime))
                ->description('Overtime requests on selected date')
                ->descriptionIcon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color($pendingOvertime > 0 ? 'info' : 'gray')
                ->icon(Heroicon::OutlinedCalendarDays),

            Stat::make('Break minutes', number_format($breakMinutes).' min')
                ->description(number_format($breakCount).' breaks, '.number_format($breakExceededMinutes).' min over limit')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color($breakExceededMinutes > 0 ? 'warning' : 'gray')
                ->icon(Heroicon::OutlinedClock),
        ];
    }

    private function getSelectedDate(): CarbonInterface
    {
        return Carbon::parse($this->pageFilters['date'] ?? today());
    }

    /**
     * @return array<int>
     */
    private function getDailyClockInTrend(CarbonInterface $endDate): array
    {
        $startDate = $endDate->copy()->subDays(6);

        $counts = Attendance::query()
            ->selectRaw('attendance_date, count(*) as aggregate')
            ->whereBetween('attendance_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotNull('time_in')
            ->groupBy('attendance_date')
            ->pluck('aggregate', 'attendance_date');

        return collect(range(0, 6))
            ->map(fn (int $offset): int => (int) ($counts[$startDate->copy()->addDays($offset)->toDateString()] ?? 0))
            ->all();
    }
}
