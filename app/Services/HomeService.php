<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class HomeService
{
    public function getAttendanceToday(?string $branch = null): Collection
    {
        $today = today()->toDateString();
        $branch = trim((string) $branch);

        return Attendance::with(['employee.media'])
            ->where('attendance_date', $today)
            ->when($branch !== '', function ($query) use ($branch): void {
                $query->whereHas('employee', function ($query) use ($branch): void {
                    $query->whereRaw('LOWER(TRIM(branch)) = ?', [strtolower($branch)]);
                });
            })
            ->whereIn('id', function ($query) use ($today) {
                $query->selectRaw('MAX(id)')
                    ->from('attendances')
                    ->where('attendance_date', $today)
                    ->groupBy('employee_id');
            })
            ->latest()
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
        return Cache::remember(Announcement::HOME_CACHE_KEY, 3600, function () {
            return Announcement::with('media')
                ->published()
                ->isNotExpired()
                ->orderByDesc('is_pinned')
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
            ->select(['id', 'employee_id', 'first_name', 'last_name', 'position', 'branch'])
            ->limit(100)
            ->get()
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'employee_id' => $employee->employee_id,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'position' => $employee->position,
                'branch' => $employee->branch,
                'profile_url' => $employee->employeeProfileUrl(),
            ])
            ->values();
    }

    public function getRegisteredEmployeeFacesExcept(?int $employeeId = null): array
    {
        $query = Employee::with('media')
            ->select(['id', 'employee_id', 'first_name', 'last_name', 'branch']);

        if ($employeeId) {
            $query->whereKeyNot($employeeId);
        }

        return $query->get()
            ->map(fn (Employee $employee): array => [
                'employee_id' => $employee->employee_id,
                'name' => $employee->first_name.' '.$employee->last_name,
                'branch' => $employee->branch,
                'profile_url' => $employee->employeeProfileUrl(),
            ])
            ->filter(fn (array $employee): bool => filled($employee['profile_url']))
            ->values()
            ->toArray();
    }
}
