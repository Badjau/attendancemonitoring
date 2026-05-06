<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\FaceController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'home'])->name('home');
Route::get('/face', [FaceController::class, 'index'])->name('face.index');
Route::get('/face/register', [FaceController::class, 'register'])->name('face.register');
Route::post('/face/register', [FaceController::class, 'storeRegistration'])->name('face.register.store');

Route::controller(AnnouncementController::class)
    ->prefix('announcements')
    ->as('announcements.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
    });

Route::controller(AttendanceController::class)
->prefix('attendance')
->as('attendance.')
->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/verify-employee', 'verifyEmployee')->name('verify-employee');
    Route::post('/record-time-in', 'recordTimeIn')->name('record-time-in');
});
