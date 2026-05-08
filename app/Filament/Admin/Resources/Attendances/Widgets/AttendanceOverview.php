<?php

namespace App\Filament\Admin\Resources\Attendances\Widgets;

use App\Enums\Attendance\Status;
use App\Models\Attendance;
use App\Models\Employee;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AttendanceOverview extends StatsOverviewWidget
{
    public ?Employee $record = null;

    protected function getStats(): array
    {
        $query = Attendance::query()
            ->where('employee_id', $this->record?->getKey());

        return [
            Stat::make('Present', number_format((clone $query)->whereNotNull('attendance_date')->count()))
                ->description('Total present records')
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->icon(Heroicon::OutlinedCalendarDays),

            Stat::make('Late', number_format((clone $query)->where('is_late', true)->count()))
                ->description('Total late records')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color('warning')
                ->icon(Heroicon::OutlinedExclamationTriangle),

            Stat::make('Overtime', number_format((clone $query)->where('is_overtime', true)->count()))
                ->description('Total overtime records')
                ->descriptionIcon(Heroicon::OutlinedArrowTrendingUp)
                ->color('info')
                ->icon(Heroicon::OutlinedBriefcase),

            Stat::make('Undertime', number_format((clone $query)->where('is_undertime', true)->count()))
                ->description('Total undertime records')
                ->descriptionIcon(Heroicon::OutlinedArrowTrendingDown)
                ->color('danger')
                ->icon(Heroicon::OutlinedCalendar),
        ];
    }
}
