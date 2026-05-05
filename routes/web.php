<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [HomeController::class, 'home'])->name('home');

Route::controller(AttendanceController::class)
->prefix('attendance')
->as('attendance.')
->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/record-time-in', 'recordTimeIn')->name('record-time-in');
});
