<?php

namespace App\Http\Controllers;

use App\Services\HomeService;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function __construct(protected HomeService $homeService) {}

    public function home()
    {
        $attendanceToday = $this->homeService->getAttendanceToday();
        $todayBirthdayCelebrants = $this->homeService->getTodayBirthdayCelebrants();
        $announcements = $this->homeService->getAnnouncements();
        $employeesWithFaces = $this->homeService->getEmployeesWithFaces();

        return Inertia::render('Home', [
            'attendanceToday' => $attendanceToday,
            'todayBirthdayCelebrants' => $todayBirthdayCelebrants,
            'announcements' => $announcements,
            'employeesWithFaces' => $employeesWithFaces,
        ]);
    }
}
