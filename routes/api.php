<?php

use App\Enums\AdminPermission;
use App\Http\Controllers\Api\V1\Admin\AdminWebServiceController;
use App\Http\Controllers\Api\V1\Admin\BlogPostController as AdminBlogPostController;
use App\Http\Controllers\Api\V1\Admin\ConsentCategoryController as AdminConsentCategoryController;
use App\Http\Controllers\Api\V1\Admin\ConsentPolicyVersionController as AdminConsentPolicyVersionController;
use App\Http\Controllers\Api\V1\Admin\ConsentPreferenceChangeController as AdminConsentPreferenceChangeController;
use App\Http\Controllers\Api\V1\Admin\ConsentRecordController as AdminConsentRecordController;
use App\Http\Controllers\Api\V1\Admin\ConsentServiceController as AdminConsentServiceController;
use App\Http\Controllers\Api\V1\Admin\PageController as AdminPageController;
use App\Http\Controllers\Api\V1\Admin\ProfessionalPublicProfileController as AdminProfessionalPublicProfileController;
use App\Http\Controllers\Api\V1\Admin\RedirectController as AdminRedirectController;
use App\Http\Controllers\Api\V1\Admin\SiteSettingController as AdminSiteSettingController;
use App\Http\Controllers\Api\V1\Admin\SpecializationController as AdminSpecializationController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CashMovementController;
use App\Http\Controllers\Api\V1\CheckupController;
use App\Http\Controllers\Api\V1\CountingPeriodController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EntityMediaController;
use App\Http\Controllers\Api\V1\ExpenseCategoryController;
use App\Http\Controllers\Api\V1\ExpenseRecordController;
use App\Http\Controllers\Api\V1\ExpenseTemplateController;
use App\Http\Controllers\Api\V1\Management\CenterSettingController as ManagementCenterSettingController;
use App\Http\Controllers\Api\V1\MarketingCampaignController;
use App\Http\Controllers\Api\V1\MarketingSegmentController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\PerformanceRecordController;
use App\Http\Controllers\Api\V1\PerformanceRecordExportController;
use App\Http\Controllers\Api\V1\ProfessionalAvailabilityExceptionController;
use App\Http\Controllers\Api\V1\ProfessionalAvailabilityRuleController;
use App\Http\Controllers\Api\V1\ProfessionalController;
use App\Http\Controllers\Api\V1\ProfessionalStatementController;
use App\Http\Controllers\Api\V1\ProfessionalTimeBlockController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\Public\SiteController as PublicSiteController;
use App\Http\Controllers\Api\V1\ReminderController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\SpecializationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::prefix('public')->group(function (): void {
        Route::get('site-settings', [PublicSiteController::class, 'settings']);
        Route::get('home', [PublicSiteController::class, 'home']);
        Route::get('search', [PublicSiteController::class, 'search']);
        Route::get('specializations', [PublicSiteController::class, 'specializations']);
        Route::get('specializations/{slug}', [PublicSiteController::class, 'specialization']);
        Route::get('services', [PublicSiteController::class, 'services']);
        Route::get('services/{slug}', [PublicSiteController::class, 'service']);
        Route::get('professionals', [PublicSiteController::class, 'professionals']);
        Route::get('professionals/{slug}', [PublicSiteController::class, 'professional']);
        Route::get('equipe', [PublicSiteController::class, 'professionals']);
        Route::get('equipe/{slug}', [PublicSiteController::class, 'professional']);
        Route::get('blog-posts', [PublicSiteController::class, 'blogPosts']);
        Route::get('blog-posts/{slug}', [PublicSiteController::class, 'blogPost']);
        Route::get('redirects/resolve', [PublicSiteController::class, 'resolveRedirect']);
        Route::get('pages/{slug}', [PublicSiteController::class, 'page']);
    });

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
        Route::post('professionals/{professional}/image', [EntityMediaController::class, 'uploadProfessionalImage']);
        Route::delete('professionals/{professional}/image', [EntityMediaController::class, 'deleteProfessionalImage']);
        Route::apiResource('professionals', ProfessionalController::class);
        Route::get('specializations/options', [SpecializationController::class, 'options']);
        Route::post('specializations/{specialization}/image', [EntityMediaController::class, 'uploadSpecializationImage']);
        Route::delete('specializations/{specialization}/image', [EntityMediaController::class, 'deleteSpecializationImage']);
        Route::post('specializations/{specialization}/icon', [EntityMediaController::class, 'uploadSpecializationIcon']);
        Route::delete('specializations/{specialization}/icon', [EntityMediaController::class, 'deleteSpecializationIcon']);
        Route::apiResource('specializations', SpecializationController::class);
        Route::post('services/{service}/image', [EntityMediaController::class, 'uploadServiceImage']);
        Route::delete('services/{service}/image', [EntityMediaController::class, 'deleteServiceImage']);
        Route::apiResource('services', ServiceController::class);
        Route::post('checkups/{checkup}/image', [EntityMediaController::class, 'uploadCheckupImage']);
        Route::delete('checkups/{checkup}/image', [EntityMediaController::class, 'deleteCheckupImage']);
        Route::post('checkups/{checkup}/icon', [EntityMediaController::class, 'uploadCheckupIcon']);
        Route::delete('checkups/{checkup}/icon', [EntityMediaController::class, 'deleteCheckupIcon']);
        Route::apiResource('checkups', CheckupController::class);
        Route::get('patients/options', [PatientController::class, 'options']);
        Route::post('patients/import', [PatientController::class, 'import']);
        Route::apiResource('patients', PatientController::class);
        Route::patch('appointments/{appointment}/move', [AppointmentController::class, 'move']);
        Route::apiResource('appointments', AppointmentController::class);
        Route::apiResource('professional-availabilities', ProfessionalAvailabilityRuleController::class)
            ->parameters(['professional-availabilities' => 'professionalAvailabilityRule'])
            ->except(['show']);
        Route::apiResource('professional-availability-exceptions', ProfessionalAvailabilityExceptionController::class)
            ->parameters(['professional-availability-exceptions' => 'availabilityException'])
            ->except(['show']);
        Route::apiResource('professional-time-blocks', ProfessionalTimeBlockController::class)
            ->parameters(['professional-time-blocks' => 'professionalTimeBlock'])
            ->except(['show']);
        Route::post('marketing-segments/preview', [MarketingSegmentController::class, 'preview']);
        Route::get('marketing-segments/{marketingSegment}/campaign-preview', [MarketingSegmentController::class, 'campaignPreview']);
        Route::apiResource('marketing-segments', MarketingSegmentController::class);
        Route::post('marketing-campaigns/{marketingCampaign}/send-test', [MarketingCampaignController::class, 'sendTest']);
        Route::post('marketing-campaigns/{marketingCampaign}/launch', [MarketingCampaignController::class, 'launch']);
        Route::apiResource('marketing-campaigns', MarketingCampaignController::class);
        Route::get('performance-records/export/preview', [PerformanceRecordExportController::class, 'preview']);
        Route::get('performance-records/export/pdf', [PerformanceRecordExportController::class, 'pdf']);
        Route::get('performance-records/export/xlsx', [PerformanceRecordExportController::class, 'excel']);
        Route::apiResource('performance-records', PerformanceRecordController::class);
        Route::apiResource('counting-periods', CountingPeriodController::class);

        Route::get('counting-periods/{countingPeriod}/summary', [CountingPeriodController::class, 'summary']);
        Route::get('counting-periods-preview/summary', [CountingPeriodController::class, 'previewSummary']);

        Route::apiResource('expense-categories', ExpenseCategoryController::class)->except(['show']);
        Route::apiResource('expense-templates', ExpenseTemplateController::class)->except(['show']);
        Route::get('expense-records/summary', [ExpenseRecordController::class, 'summary']);
        Route::apiResource('expense-records', ExpenseRecordController::class);
        Route::post('cash-movements/reset', [CashMovementController::class, 'reset']);
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

        Route::prefix('management/settings/center')
            ->middleware('permission:'.AdminPermission::MANAGE_CENTER_SETTINGS->value)
            ->group(function (): void {
                Route::get('/', [ManagementCenterSettingController::class, 'show']);
                Route::put('/', [ManagementCenterSettingController::class, 'update']);
                Route::post('logo', [ManagementCenterSettingController::class, 'uploadLogo']);
                Route::delete('logo', [ManagementCenterSettingController::class, 'deleteLogo']);
            });

        Route::prefix('admin')
            ->middleware('permission:'.AdminPermission::VIEW_BACKOFFICE->value)
            ->group(function (): void {
                Route::apiResource('users', AdminUserController::class)
                    ->middleware('permission:manage users');
                Route::apiResource('pages', AdminPageController::class)
                    ->middleware('permission:manage pages');
                Route::post('pages/media', [AdminPageController::class, 'uploadMedia'])
                    ->middleware('permission:manage pages');
                Route::apiResource('blog-posts', AdminBlogPostController::class)
                    ->middleware('permission:manage blog posts');
                Route::apiResource('redirects', AdminRedirectController::class)
                    ->middleware('permission:manage redirects');
                Route::apiResource('specializations', AdminSpecializationController::class)
                    ->only(['index', 'show', 'update'])
                    ->middleware('permission:manage specializations');
                Route::apiResource('services', AdminWebServiceController::class)
                    ->only(['index', 'show', 'update'])
                    ->middleware('permission:manage services');
                Route::apiResource('professional-public-profiles', AdminProfessionalPublicProfileController::class)
                    ->parameters(['professional-public-profiles' => 'professionalPublicProfile'])
                    ->middleware('permission:manage doctors');
                Route::patch('professional-public-profiles/{professionalPublicProfile}/sections', [AdminProfessionalPublicProfileController::class, 'updateSections'])
                    ->middleware('permission:manage doctors');
                Route::apiResource('equipe', AdminProfessionalPublicProfileController::class)
                    ->parameters(['equipe' => 'professionalPublicProfile'])
                    ->middleware('permission:manage doctors');
                Route::patch('equipe/{professionalPublicProfile}/sections', [AdminProfessionalPublicProfileController::class, 'updateSections'])
                    ->middleware('permission:manage doctors');
                Route::apiResource('consent-categories', AdminConsentCategoryController::class)
                    ->middleware('permission:manage consent configuration');
                Route::apiResource('consent-services', AdminConsentServiceController::class)
                    ->middleware('permission:manage consent configuration');
                Route::apiResource('consent-policy-versions', AdminConsentPolicyVersionController::class)
                    ->middleware('permission:manage consent configuration');
                Route::apiResource('consent-records', AdminConsentRecordController::class)
                    ->only(['index', 'show'])
                    ->middleware('permission:view consent records');
                Route::apiResource('consent-preference-changes', AdminConsentPreferenceChangeController::class)
                    ->only(['index', 'show'])
                    ->middleware('permission:view consent records');
                Route::get('site-settings', [AdminSiteSettingController::class, 'show'])
                    ->middleware('permission:manage settings');
                Route::put('site-settings', [AdminSiteSettingController::class, 'update'])
                    ->middleware('permission:manage settings');
            });
    });
});
