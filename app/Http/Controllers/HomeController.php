<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function home()
    {
        $attendanceToday = Attendance::with(['employee.media'])
            ->whereDate('attendance_date', Carbon::now())
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

        return Inertia::render('Home', [
            'attendanceToday' => $attendanceToday,
            'todayBirthdayCelebrants' => $todayBirthdayCelebrants,
            'announcements' => $announcements,
        ]);
    }
}
