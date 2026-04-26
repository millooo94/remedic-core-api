<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CashMovementController;
use App\Http\Controllers\Api\V1\CountingPeriodController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ExpenseCategoryController;
use App\Http\Controllers\Api\V1\ExpenseRecordController;
use App\Http\Controllers\Api\V1\ExpenseTemplateController;
use App\Http\Controllers\Api\V1\PerformanceRecordController;
use App\Http\Controllers\Api\V1\ProfessionalController;
use App\Http\Controllers\Api\V1\ProfessionalStatementController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ReminderController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\SettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::prefix('auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('email/resend', [AuthController::class, 'resendVerification']);
        Route::post('approval/resend', [AuthController::class, 'resendApprovalRequest']);
        Route::get('email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');
        Route::get('access-requests/{id}/approve/{hash}', [AuthController::class, 'approveAccessRequest'])
            ->middleware(['signed', 'throttle:6,1'])
            ->name('access-requests.approve');
        Route::get('access-requests/{id}/reject/{hash}', [AuthController::class, 'rejectAccessRequest'])
            ->middleware(['signed', 'throttle:6,1'])
            ->name('access-requests.reject');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('email/verification-notification', [AuthController::class, 'resendVerificationForAuthenticated']);
        });
    });

    Route::middleware(['auth:sanctum', 'verified', 'admin'])->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::get('profile', [ProfileController::class, 'show']);
        Route::put('profile', [ProfileController::class, 'update']);
        Route::post('profile/avatar', [ProfileController::class, 'updateAvatar']);
        Route::put('profile/password', [ProfileController::class, 'updatePassword']);
        Route::apiResource('professionals', ProfessionalController::class);
        Route::apiResource('services', ServiceController::class);
        Route::apiResource('performance-records', PerformanceRecordController::class);
        Route::apiResource('counting-periods', CountingPeriodController::class);

        Route::get('counting-periods/{countingPeriod}/summary', [CountingPeriodController::class, 'summary']);
        Route::get('counting-periods-preview/summary', [CountingPeriodController::class, 'previewSummary']);

        Route::apiResource('expense-categories', ExpenseCategoryController::class)->except(['show']);
        Route::apiResource('expense-templates', ExpenseTemplateController::class)->except(['show']);
        Route::apiResource('expense-records', ExpenseRecordController::class);
        Route::get('cash-movements/summary', [CashMovementController::class, 'summary']);
        Route::apiResource('cash-movements', CashMovementController::class);
        Route::apiResource('reminders', ReminderController::class)->except(['show']);

        Route::get('dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('dashboard/monthly-trends', [DashboardController::class, 'monthlyTrends']);

        Route::get('professional-statements/{professional}', [ProfessionalStatementController::class, 'show']);
        Route::get('professional-statements/{professional}/pdf', [ProfessionalStatementController::class, 'pdf']);
        Route::get('professional-statements/{professional}/xlsx', [ProfessionalStatementController::class, 'excel']);

        Route::get('settings', [SettingsController::class, 'show']);
        Route::put('settings', [SettingsController::class, 'update']);
    });
});
