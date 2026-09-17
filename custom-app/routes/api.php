<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\VerificationController;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware([AuthenticateApiKey::class])->group(function (): void {
    Route::post('verifications', [VerificationController::class, 'store']);
    Route::post('verifications/{id}/check', [VerificationController::class, 'check']);
    Route::get('verifications/{id}', [VerificationController::class, 'show']);
    Route::delete('verifications/{id}', [VerificationController::class, 'destroy']);
});
