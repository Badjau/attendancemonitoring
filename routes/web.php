<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'home'])->name('home');

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
    Route::post('/record-time-in', 'recordTimeIn')->name('record-time-in');
});
