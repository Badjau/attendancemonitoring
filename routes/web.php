<?php

use App\Http\Controllers\AdminAccessController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\DatabaseHealthController;
use App\Http\Controllers\EmployeeWebAuthnController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\TimeclockUnlockController;
use App\Services\HomeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/health/database', DatabaseHealthController::class)
    ->name('health.database');

Route::get('/csrf-token', function (Request $request) {
    return response()
        ->json(['token' => csrf_token()])
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
})->name('csrf-token');

Route::controller(TimeclockUnlockController::class)
    ->prefix('unlock')
    ->as('timeclock.')
    ->group(function () {
        Route::get('/', 'create')->name('unlock');
        Route::post('/', 'store')->name('unlock.store');
        Route::post('/lock', 'destroy')->name('lock');
    });

Route::controller(AdminAccessController::class)
    ->prefix('admin')
    ->as('admin.access.')
    ->group(function () {
        Route::get('/login', 'showLogin')->name('login');
        Route::post('/login', 'login')->name('login.store');
        Route::get('/register', 'showRegister')->name('register');
        Route::post('/register', 'register')->name('register.store');
        Route::match(['get', 'post'], '/password-logout', 'logout')->name('logout');
    });

Route::get('/face', fn () => redirect()->route('home'))->name('face');
Route::get('/face/register', fn () => redirect()->route('home'))->name('face.register');
Route::get('/face/diagnostics', fn (HomeService $homeService) => Inertia::render('Face/Index', [
    'employees' => $homeService->getEmployeesWithFaces(),
]))->name('face.diagnostics');

Route::get('/', [HomeController::class, 'home'])
    ->middleware('timeclock.unlocked')
    ->name('home');

Route::get('/offline-attendance', fn () => Inertia::render('OfflineAttendance/Index'))
    ->name('offline-attendance.index');

// ROUTE FOR ANNOUNCEMENT
Route::controller(AnnouncementController::class)
    ->prefix('announcements')
    ->as('announcements.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
    });

// ROUTE FOR ATTENDANCE
Route::controller(AttendanceController::class)
    ->middleware('timeclock.unlocked')
    ->prefix('attendance')
    ->as('attendance.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/current-time', 'currentTime')->name('current-time');
        Route::post('/verify-employee', 'verifyEmployee')->name('verify-employee');
        Route::post('/record-time-in', 'recordTimeIn')->name('record-time-in');
    });

// ROUTE FOR EMPLOYEE WEB AUTHN (FINGERPRINT)
Route::controller(EmployeeWebAuthnController::class)
    ->middleware('timeclock.unlocked')
    ->prefix('attendance/fingerprint')
    ->as('attendance.fingerprint.')
    ->group(function () {
        Route::post('/options', 'assertionOptions')->name('options');
        Route::post('/record', 'recordAttendance')->name('record');
    });

// ROUTE FOR EMPLOYEE WEB AUTHN (ADMIN)
Route::controller(EmployeeWebAuthnController::class)
    ->middleware('admin.unlocked')
    ->prefix('admin/employees/{employee}/fingerprint')
    ->as('admin.employees.fingerprint.')
    ->group(function () {
        Route::post('/options', 'registrationOptions')->name('options');
        Route::post('/', 'register')->name('register');
        Route::delete('/finger', 'destroyFinger')->name('destroy-finger');
        Route::delete('/scanner-finger', 'destroyScannerFinger')->name('destroy-scanner-finger');
    });
