<?php

use App\Enums\AdminPermission;
use App\Http\Controllers\Api\V1\Admin\AdminWebCheckupController;
use App\Http\Controllers\Api\V1\Admin\AdminWebServiceController;
use App\Http\Controllers\Api\V1\Admin\BlogPostController as AdminBlogPostController;
use App\Http\Controllers\Api\V1\Admin\ConsentCategoryController as AdminConsentCategoryController;
use App\Http\Controllers\Api\V1\Admin\ConsentPolicyVersionController as AdminConsentPolicyVersionController;
use App\Http\Controllers\Api\V1\Admin\ConsentPreferenceChangeController as AdminConsentPreferenceChangeController;
use App\Http\Controllers\Api\V1\Admin\ConsentRecordController as AdminConsentRecordController;
use App\Http\Controllers\Api\V1\Admin\ConsentServiceController as AdminConsentServiceController;
use App\Http\Controllers\Api\V1\Admin\MedicalAreaController as AdminMedicalAreaController;
use App\Http\Controllers\Api\V1\Admin\PageController as AdminPageController;
use App\Http\Controllers\Api\V1\Admin\ProfessionalPublicProfileController as AdminProfessionalPublicProfileController;
use App\Http\Controllers\Api\V1\Admin\RedirectController as AdminRedirectController;
use App\Http\Controllers\Api\V1\Admin\SiteSettingController as AdminSiteSettingController;
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
        Route::get('aree-mediche', [PublicSiteController::class, 'medicalAreas']);
        Route::get('aree-mediche/{slug}', [PublicSiteController::class, 'medicalArea']);
        Route::get('services', [PublicSiteController::class, 'services']);
        Route::get('services/{slug}', [PublicSiteController::class, 'service']);
        Route::get('prestazioni', [PublicSiteController::class, 'prestazioni']);
        Route::get('prestazioni/{slug}', [PublicSiteController::class, 'prestazione']);
        Route::get('check-up', [PublicSiteController::class, 'checkups']);
        Route::get('check-up/{publicSlug}', [PublicSiteController::class, 'checkup']);
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
        Route::prefix('professionals')
            ->middleware('permission:'.AdminPermission::MANAGE_DOCTORS->value)
            ->group(function (): void {
                Route::post('{professional}/image', [EntityMediaController::class, 'uploadProfessionalImage']);
                Route::delete('{professional}/image', [EntityMediaController::class, 'deleteProfessionalImage']);
            });
        Route::apiResource('professionals', ProfessionalController::class)
            ->middleware('permission:'.AdminPermission::MANAGE_DOCTORS->value);
        Route::prefix('specializations')
            ->middleware('permission:'.AdminPermission::MANAGE_SPECIALIZATIONS->value)
            ->group(function (): void {
                Route::get('options', [SpecializationController::class, 'options']);
                Route::post('{specialization}/image', [EntityMediaController::class, 'uploadSpecializationImage']);
                Route::delete('{specialization}/image', [EntityMediaController::class, 'deleteSpecializationImage']);
                Route::post('{specialization}/icon', [EntityMediaController::class, 'uploadSpecializationIcon']);
                Route::delete('{specialization}/icon', [EntityMediaController::class, 'deleteSpecializationIcon']);
            });
        Route::apiResource('specializations', SpecializationController::class)
            ->middleware('permission:'.AdminPermission::MANAGE_SPECIALIZATIONS->value);
        Route::prefix('services')
            ->middleware('permission:'.AdminPermission::MANAGE_SERVICES->value)
            ->group(function (): void {
                Route::post('{service}/image', [EntityMediaController::class, 'uploadServiceImage']);
                Route::delete('{service}/image', [EntityMediaController::class, 'deleteServiceImage']);
                Route::post('{service}/restore', [ServiceController::class, 'restore']);
                Route::delete('{service}/force', [ServiceController::class, 'forceDestroy']);
            });
        Route::apiResource('services', ServiceController::class)
            ->middleware('permission:'.AdminPermission::MANAGE_SERVICES->value);
        Route::prefix('checkups')->middleware('permission:'.AdminPermission::MANAGE_SERVICES->value)->group(function (): void {
            Route::post('{checkup}/image', [EntityMediaController::class, 'uploadCheckupImage']);
            Route::delete('{checkup}/image', [EntityMediaController::class, 'deleteCheckupImage']);
            Route::post('{checkup}/icon', [EntityMediaController::class, 'uploadCheckupIcon']);
            Route::delete('{checkup}/icon', [EntityMediaController::class, 'deleteCheckupIcon']);
            Route::post('{checkup}/restore', [CheckupController::class, 'restore']);
            Route::delete('{checkup}/force', [CheckupController::class, 'forceDestroy']);
        });
        Route::apiResource('checkups', CheckupController::class)
            ->middleware('permission:'.AdminPermission::MANAGE_SERVICES->value);
        Route::get('patients/options', [PatientController::class, 'options']);
        Route::post('patients/import', [PatientController::class, 'import']);
        Route::apiResource('patients', PatientController::class);
        Route::patch('appointments/{appointment}/move', [AppointmentController::class, 'move']);
        Route::apiResource('appointments', AppointmentController::class);
        Route::apiResource('professional-availabilities', ProfessionalAvailabilityRuleController::class)
            ->parameters(['professional-availabilities' => 'professionalAvailabilityRule'])
            ->except(['show'])
            ->middleware('permission:'.AdminPermission::MANAGE_DOCTORS->value);
        Route::apiResource('professional-availability-exceptions', ProfessionalAvailabilityExceptionController::class)
            ->parameters(['professional-availability-exceptions' => 'availabilityException'])
            ->except(['show'])
            ->middleware('permission:'.AdminPermission::MANAGE_DOCTORS->value);
        Route::apiResource('professional-time-blocks', ProfessionalTimeBlockController::class)
            ->parameters(['professional-time-blocks' => 'professionalTimeBlock'])
            ->except(['show'])
            ->middleware('permission:'.AdminPermission::MANAGE_DOCTORS->value);
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

        Route::prefix('professional-statements')
            ->middleware('permission:'.AdminPermission::MANAGE_DOCTORS->value)
            ->group(function (): void {
                Route::get('{professional}', [ProfessionalStatementController::class, 'show']);
                Route::get('{professional}/pdf', [ProfessionalStatementController::class, 'pdf']);
                Route::get('{professional}/xlsx', [ProfessionalStatementController::class, 'excel']);
            });

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
                    ->middleware('permission:'.AdminPermission::MANAGE_USERS->value);
                Route::apiResource('pages', AdminPageController::class)
                    ->middleware('permission:'.AdminPermission::MANAGE_PAGES->value);
                Route::post('pages/media', [AdminPageController::class, 'uploadMedia'])
                    ->middleware('permission:'.AdminPermission::MANAGE_PAGES->value);
                Route::delete('pages/{page}/sections/{sectionKey}/media', [AdminPageController::class, 'deleteSectionMedia'])
                    ->middleware('permission:'.AdminPermission::MANAGE_PAGES->value);
                Route::apiResource('blog-posts', AdminBlogPostController::class)
                    ->middleware('permission:'.AdminPermission::MANAGE_BLOG_POSTS->value);
                Route::apiResource('redirects', AdminRedirectController::class)
                    ->middleware('permission:'.AdminPermission::MANAGE_REDIRECTS->value);
                Route::get('aree-mediche', [AdminMedicalAreaController::class, 'index'])
                    ->middleware('permission:'.AdminPermission::MANAGE_SPECIALIZATIONS->value);
                Route::get('aree-mediche/{specialization}', [AdminMedicalAreaController::class, 'show'])
                    ->middleware('permission:'.AdminPermission::MANAGE_SPECIALIZATIONS->value);
                Route::match(['put', 'patch'], 'aree-mediche/{specialization}', [AdminMedicalAreaController::class, 'update'])
                    ->middleware('permission:'.AdminPermission::MANAGE_SPECIALIZATIONS->value);
                Route::get('specializations', [AdminMedicalAreaController::class, 'index'])
                    ->middleware('permission:'.AdminPermission::MANAGE_SPECIALIZATIONS->value);
                Route::get('specializations/{specialization}', [AdminMedicalAreaController::class, 'show'])
                    ->middleware('permission:'.AdminPermission::MANAGE_SPECIALIZATIONS->value);
                Route::match(['put', 'patch'], 'specializations/{specialization}', [AdminMedicalAreaController::class, 'update'])
                    ->middleware('permission:'.AdminPermission::MANAGE_SPECIALIZATIONS->value);
                foreach (['prestazioni', 'services'] as $serviceWebRoute) {
                    Route::get($serviceWebRoute, [AdminWebServiceController::class, 'index'])
                        ->middleware('permission:'.AdminPermission::MANAGE_SERVICES->value);
                    Route::get($serviceWebRoute.'/{service}', [AdminWebServiceController::class, 'show'])
                        ->middleware('permission:'.AdminPermission::MANAGE_SERVICES->value);
                    Route::match(['put', 'patch'], $serviceWebRoute.'/{service}', [AdminWebServiceController::class, 'update'])
                        ->middleware('permission:'.AdminPermission::MANAGE_SERVICES->value);
                }
                Route::get('check-up', [AdminWebCheckupController::class, 'index'])
                    ->middleware('permission:'.AdminPermission::MANAGE_SERVICES->value);
                Route::get('check-up/{checkup}', [AdminWebCheckupController::class, 'show'])
                    ->middleware('permission:'.AdminPermission::MANAGE_SERVICES->value);
                Route::match(['put', 'patch'], 'check-up/{checkup}', [AdminWebCheckupController::class, 'update'])
                    ->middleware('permission:'.AdminPermission::MANAGE_SERVICES->value);
                Route::apiResource('professional-public-profiles', AdminProfessionalPublicProfileController::class)
                    ->parameters(['professional-public-profiles' => 'professionalPublicProfile'])
                    ->middleware('permission:'.AdminPermission::MANAGE_DOCTORS->value);
                Route::patch('professional-public-profiles/{professionalPublicProfile}/sections', [AdminProfessionalPublicProfileController::class, 'updateSections'])
                    ->middleware('permission:'.AdminPermission::MANAGE_DOCTORS->value);
                Route::apiResource('equipe', AdminProfessionalPublicProfileController::class)
                    ->parameters(['equipe' => 'professionalPublicProfile'])
                    ->middleware('permission:'.AdminPermission::MANAGE_DOCTORS->value);
                Route::patch('equipe/{professionalPublicProfile}/sections', [AdminProfessionalPublicProfileController::class, 'updateSections'])
                    ->middleware('permission:'.AdminPermission::MANAGE_DOCTORS->value);
                Route::apiResource('consent-categories', AdminConsentCategoryController::class)
                    ->middleware('permission:'.AdminPermission::MANAGE_CONSENT_CONFIGURATION->value);
                Route::apiResource('consent-services', AdminConsentServiceController::class)
                    ->middleware('permission:'.AdminPermission::MANAGE_CONSENT_CONFIGURATION->value);
                Route::apiResource('consent-policy-versions', AdminConsentPolicyVersionController::class)
                    ->middleware('permission:'.AdminPermission::MANAGE_CONSENT_CONFIGURATION->value);
                Route::apiResource('consent-records', AdminConsentRecordController::class)
                    ->only(['index', 'show'])
                    ->middleware('permission:'.AdminPermission::VIEW_CONSENT_RECORDS->value);
                Route::apiResource('consent-preference-changes', AdminConsentPreferenceChangeController::class)
                    ->only(['index', 'show'])
                    ->middleware('permission:'.AdminPermission::VIEW_CONSENT_RECORDS->value);
                Route::get('site-settings', [AdminSiteSettingController::class, 'show'])
                    ->middleware('permission:'.AdminPermission::MANAGE_SETTINGS->value);
                Route::put('site-settings', [AdminSiteSettingController::class, 'update'])
                    ->middleware('permission:'.AdminPermission::MANAGE_SETTINGS->value);
            });
    });
});
