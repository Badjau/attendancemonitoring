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

        $dailyAttendances = Attendance::query()
            ->whereDate('attendance_date', $date);

        $clockedIn = (clone $dailyAttendances)
            ->whereNotNull('time_in')
            ->count();

        $late = (clone $dailyAttendances)
            ->where('is_late', true)
            ->count();

        $pendingOvertime = (clone $dailyAttendances)
            ->where('overtime_status', OvertimeStatus::Pending->value)
            ->count();

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
