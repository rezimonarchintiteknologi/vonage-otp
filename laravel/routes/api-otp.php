<?php

/*
 * Salin isi file ini ke routes/api.php milikmu.
 *
 * throttle di level route adalah lapis pertama.
 * Rate limit per nomor ada di dalam OtpService (lapis kedua),
 * karena satu IP bisa menyerang banyak nomor dan sebaliknya.
 */

use App\Http\Controllers\Api\OtpController;
use Illuminate\Support\Facades\Route;

Route::prefix('otp')->middleware('throttle:20,1')->group(function () {
    Route::post('/request', [OtpController::class, 'send']);
    Route::post('/verify', [OtpController::class, 'verify']);
});
