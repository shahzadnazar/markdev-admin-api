<?php

use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\AssignmentController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\BiometricPunchController;
use App\Http\Controllers\Api\V1\BookmarkController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CertificateController;
use App\Http\Controllers\Api\V1\MaterialController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\CourseController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\HelpController;
use App\Http\Controllers\Api\V1\LeaderboardController;
use App\Http\Controllers\Api\V1\LeaveApplicationController;
use App\Http\Controllers\Api\V1\LessonController;
use App\Http\Controllers\Api\V1\ModuleController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProgressController;
use App\Http\Controllers\Api\V1\QuizAttemptController;
use App\Http\Controllers\Api\V1\QuizController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    /* ---------------------- Biometric devices (X-Device-Key) --------------- */

    Route::post('biometric/punches', [BiometricPunchController::class, 'store'])
        ->middleware(\App\Http\Middleware\AuthenticateBiometricDevice::class);

    /* ------------------------------- Guest -------------------------------- */

    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);

    // Signed URL so the portal can download via a plain <a href> (no bearer
    // header); the signature scopes access to the exact certificate.
    Route::get('certificates/{certificate}/download', [CertificateController::class, 'download'])
        ->name('api.v1.certificates.download')
        ->middleware('signed');

    Route::get('billing/invoices/{invoice}/receipt', [BillingController::class, 'receipt'])
        ->name('api.v1.billing.invoices.receipt')
        ->middleware('signed');

    /* ---------------------------- Authenticated --------------------------- */

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::put('auth/password', [AuthController::class, 'updatePassword']);
        Route::put('auth/profile', [AuthController::class, 'updateProfile']);
        Route::post('auth/avatar', [AuthController::class, 'updateAvatar']);

        /* Catalog */
        Route::get('categories', [CategoryController::class, 'index']);
        Route::get('courses', [CourseController::class, 'index']);
        Route::get('courses/{course}', [CourseController::class, 'show']);
        Route::get('courses/{course}/modules', [ModuleController::class, 'index']);
        Route::post('courses/{course}/enroll', [CourseController::class, 'enroll']);

        Route::scopeBindings()->group(function () {
            Route::get('courses/{course}/lessons/{lesson}', [LessonController::class, 'show']);
            Route::post('courses/{course}/lessons/{lesson}/complete', [LessonController::class, 'complete']);
            Route::delete('courses/{course}/lessons/{lesson}/complete', [LessonController::class, 'uncomplete']);
            Route::post(
    'courses/{course}/lessons/{lesson}/activity',
    [LessonController::class, 'activity']
);
        });

        Route::get('lessons/{lesson}/comments', [CommentController::class, 'index']);
        Route::post('lessons/{lesson}/comments', [CommentController::class, 'store']);

        /* Assessments */
        Route::get('assignments', [AssignmentController::class, 'index']);
        Route::get('assignments/{assignment}', [AssignmentController::class, 'show']);
        Route::post('assignments/{assignment}/submissions', [AssignmentController::class, 'submit']);

        Route::get('quizzes', [QuizController::class, 'index']);
        Route::get('quizzes/{quiz}', [QuizController::class, 'show']);
        Route::get('quizzes/{quiz}/attempts', [QuizAttemptController::class, 'index']);
        Route::post('quizzes/{quiz}/attempts', [QuizAttemptController::class, 'store']);
        Route::get('quizzes/{quiz}/attempts/{attempt}', [QuizAttemptController::class, 'show']);
        Route::post('quizzes/{quiz}/attempts/{attempt}/submit', [QuizAttemptController::class, 'submit']);

        /* Engagement */
        Route::get('dashboard', DashboardController::class);
        Route::get('attendance', [AttendanceController::class, 'index']);
        Route::get('attendance/summary', [AttendanceController::class, 'summary']);
        Route::get('attendance/daily', [AttendanceController::class, 'daily']);
        Route::get('leaves', [LeaveApplicationController::class, 'index']);
        Route::post('leaves', [LeaveApplicationController::class, 'store']);
        Route::get('certificates', [CertificateController::class, 'index']);

        /* Study materials */
        Route::get('materials', [MaterialController::class, 'index']);
        Route::post('materials/{resource}/read', [MaterialController::class, 'read']);
        Route::get('progress', ProgressController::class);
        Route::get('leaderboard', LeaderboardController::class);

        Route::get('announcements', [AnnouncementController::class, 'index']);
        Route::get('announcements/{announcement}', [AnnouncementController::class, 'show']);
        Route::post('announcements/{announcement}/read', [AnnouncementController::class, 'read']);

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/counts', [NotificationController::class, 'counts']);
        Route::patch('notifications/{id}/read', [NotificationController::class, 'read']);
        Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
        Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);

        Route::get('bookmarks', [BookmarkController::class, 'index']);
        Route::post('bookmarks', [BookmarkController::class, 'store']);
        Route::delete('bookmarks/{type}/{id}', [BookmarkController::class, 'destroy']);

        Route::get('calendar', CalendarController::class);
        Route::get('search', SearchController::class);

        Route::get('help/categories', [HelpController::class, 'categories']);
        Route::get('help/articles', [HelpController::class, 'articles']);
        Route::get('help/articles/{slug}', [HelpController::class, 'article']);
        Route::get('help/faqs', [HelpController::class, 'faqs']);

        Route::get('settings', [SettingsController::class, 'show']);
        Route::put('settings', [SettingsController::class, 'update']);

        /* Billing */
        Route::get('billing', [BillingController::class, 'overview']);
        Route::get('billing/transactions', [BillingController::class, 'transactions']);
        Route::get('billing/invoices', [BillingController::class, 'invoices']);
        Route::post('billing/invoices/{invoice}/submissions', [BillingController::class, 'submitPayment']);
    });
});
