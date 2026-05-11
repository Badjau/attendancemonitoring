<?php

use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\EmployeeWebAuthnController;
use App\Http\Controllers\FaceController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\TimeclockUnlockController;
use Illuminate\Support\Facades\Route;

Route::controller(TimeclockUnlockController::class)
    ->prefix('unlock')
    ->as('timeclock.')
    ->group(function () {
        Route::get('/', 'create')->name('unlock');
        Route::post('/', 'store')->name('unlock.store');
        Route::post('/lock', 'destroy')->name('lock');
    });

Route::get('/', [HomeController::class, 'home'])
    ->middleware('timeclock.unlocked')
    ->name('home');

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
    ->middleware('auth')
    ->prefix('admin/employees/{employee}/fingerprint')
    ->as('admin.employees.fingerprint.')
    ->group(function () {
        Route::post('/options', 'registrationOptions')->name('options');
        Route::post('/', 'register')->name('register');
    });

// ROUTE FOR FACE RECOGNITION
Route::controller(FaceController::class)
    ->group(function () {
        Route::post('/face/register', 'storeRegistration')->name('face.register.store');
        Route::get('/face/register', [FaceController::class, 'register'])->name('face.register');
        Route::post('/face/register', [FaceController::class, 'storeRegistration'])->name('face.register.store');
        Route::post('/admin/employees/{employee}/face', 'storeEmployeeRegistration')->middleware('auth')->name('admin.employees.face.register');
    });
