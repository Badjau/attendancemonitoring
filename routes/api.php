<?php

use App\Http\Controllers\Api\LocalZktecoBridgeController;
use App\Http\Controllers\Api\ZktecoFingerprintController;
use Illuminate\Support\Facades\Route;

Route::prefix('zkteco')->controller(ZktecoFingerprintController::class)->group(function () {
    Route::get('/employees', 'employees');
    Route::get('/fingerprints', 'fingerprints');
    Route::post('/fingerprints/enroll', 'enroll');
    Route::post('/attendance', 'recordAttendance');
});

Route::match(['get', 'post'], 'local-zkteco-bridge/{endpoint}', [LocalZktecoBridgeController::class, 'handle']);
