<?php

namespace App\Http\Controllers;

use App\Services\AttendanceScheduleSettings;
use App\Services\HomeService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function __construct(
        protected HomeService $homeService,
        protected AttendanceScheduleSettings $attendanceScheduleSettings,
    ) {}

    public function home(Request $request)
    {
        $attendanceToday = $this->homeService->getAttendanceToday($request->query('branch'));
        $todayBirthdayCelebrants = $this->homeService->getTodayBirthdayCelebrants();
        $announcements = $this->homeService->getAnnouncements();
        $employeesWithFaces = $this->homeService->getEmployeesWithFaces();

        return Inertia::render('Home', [
            'attendanceToday' => $attendanceToday,
            'todayBirthdayCelebrants' => $todayBirthdayCelebrants,
            'announcements' => $announcements,
            'employeesWithFaces' => $employeesWithFaces,
            'attendanceSchedule' => $this->attendanceScheduleSettings->toArray(),
            'zktecoBridgeUrl' => config('services.zkteco.bridge_url'),
        ]);
    }
}
