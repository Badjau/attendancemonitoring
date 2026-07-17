<?php

use App\Http\Controllers\Api\KioskAttendanceController;
use App\Http\Controllers\Api\KioskAuthController;
use App\Http\Controllers\Api\ZktecoFingerprintController;
use App\Http\Controllers\FaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('zkteco')->controller(ZktecoFingerprintController::class)->group(function () {
    Route::get('/employees', 'employees');
    Route::get('/fingerprints/manifest', 'fingerprintsManifest');
    Route::get('/fingerprints', 'fingerprints');
    Route::post('/fingerprints/enroll', 'enroll');
    Route::post('/attendance', 'recordAttendance');
});

Route::prefix('face')->controller(FaceController::class)->group(function () {
    Route::get('/employees/{employee:employee_id}/embeddings', 'employeeEmbeddings');
    Route::post('/employees/{employee:employee_id}/embeddings', 'storeEmployeeEmbedding');
    Route::delete('/employees/{employee:employee_id}/embeddings', 'destroyEmployeeEmbeddings');
});

Route::middleware('kiosk.token')->group(function () {
    Route::prefix('kiosk/auth')->controller(KioskAuthController::class)->group(function () {
        Route::get('/manifest', 'manifest');
        Route::get('/sync', 'sync');
        Route::get('/full', 'full');
    });

    Route::post('/kiosk/attendance/sync', [KioskAttendanceController::class, 'sync']);
});
