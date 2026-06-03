<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class HomeService
{
    public function getAttendanceToday(): Collection
    {
        $today = today()->toDateString();

        return Attendance::with(['employee.media'])
            ->where('attendance_date', $today)
            ->whereIn('id', function ($query) use ($today) {
                $query->selectRaw('MAX(id)')
                    ->from('attendances')
                    ->where('attendance_date', $today)
                    ->groupBy('employee_id');
            })
            ->latest()
            ->take(10)
            ->get();
    }

    public function getTodayBirthdayCelebrants(): Collection
    {
        return Employee::with(['media', 'department'])
            ->whereMonth('date_of_birth', today()->month)
            ->whereDay('date_of_birth', today()->day)
            ->get();
    }

    public function getAnnouncements(): Collection
    {
        // Cache announcements for 1 hour to reduce database queries
        return Cache::remember('announcements_pinned_published', 3600, function () {
            return Announcement::with('media')
                ->isPinned()
                ->published()
                ->isNotExpired()
                ->latest()
                ->take(2)
                ->get();
        });
    }

    public function getEmployeesWithFaces(): \Illuminate\Support\Collection
    {
        // Limit to 100 employees max to prevent loading all employees into memory
        // This is better for systems with many employees
        return Employee::with('media')
            ->select(['id', 'employee_id', 'first_name', 'last_name', 'position'])
            ->limit(100)
            ->get()
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'employee_id' => $employee->employee_id,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'position' => $employee->position,
                'profile_url' => $employee->getFirstMediaUrl('employee-profile'),
            ])
            ->filter(fn (array $employee): bool => filled($employee['profile_url']))
            ->values();
    }

    public function getRegisteredEmployeeFacesExcept(?int $employeeId = null): array
    {
        // Cache per request to avoid repeated database queries during same request
        return Cache::remember("registered_faces_{$employeeId}", 3600, function () use ($employeeId) {
            $query = Employee::with('media')
                ->select(['id', 'employee_id', 'first_name', 'last_name']);

            if ($employeeId) {
                $query->whereKeyNot($employeeId);
            }

            return $query->get()
                ->map(fn (Employee $employee): array => [
                    'employee_id' => $employee->employee_id,
                    'name' => $employee->first_name . ' ' . $employee->last_name,
                    'profile_url' => $employee->getFirstMediaUrl('employee-profile'),
                ])
                ->filter(fn (array $employee): bool => filled($employee['profile_url']))
                ->values()
                ->toArray();
        });
    }
}
