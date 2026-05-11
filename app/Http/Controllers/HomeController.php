<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\Employee;
use Carbon\Carbon;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function home()
    {
        $attendanceToday = Attendance::with(['employee.media'])
            ->whereDate('attendance_date', Carbon::now())
            ->whereIn('id', function ($query) {
                $query->selectRaw('MAX(id)')
                    ->from('attendances')
                    ->whereDate('attendance_date', today())
                    ->groupBy('employee_id');
            })
            ->latest()
            ->take(10)
            ->get();

        $todayBirthdayCelebrants = Employee::with(['media', 'department'])
            ->whereMonth('date_of_birth', Carbon::now()->month)
            ->whereDay('date_of_birth', Carbon::now()->day)
            ->get();

        $announcements = Announcement::with('media')
            ->isPinned()
            ->published()
            ->latest()
            ->take(2)
            ->get();

        $employeesWithFaces = Employee::with('media')
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

        return Inertia::render('Home', [
            'attendanceToday' => $attendanceToday,
            'todayBirthdayCelebrants' => $todayBirthdayCelebrants,
            'announcements' => $announcements,
            'employeesWithFaces' => $employeesWithFaces,
        ]);
    }
}
