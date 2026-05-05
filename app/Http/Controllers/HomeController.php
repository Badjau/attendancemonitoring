<?php

namespace App\Http\Controllers;

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
            ->get();

        $currentMonthBirthdayCelebrants = Employee::with(['media'])->whereMonth('date_of_birth', Carbon::now()->month)->get();

        return Inertia::render('Home', [
            'attendanceToday' => $attendanceToday,
            'currentMonthBirthdayCelebrants' => $currentMonthBirthdayCelebrants
        ]);
    }
}
