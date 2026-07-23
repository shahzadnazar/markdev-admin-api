<?php

use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\AssignmentController;
use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CertificateController;
use App\Http\Controllers\Admin\CourseController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EnrollmentController;
use App\Http\Controllers\Admin\HelpController;
use App\Http\Controllers\Admin\LessonController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\ModuleController;
use App\Http\Controllers\Admin\QuestionController;
use App\Http\Controllers\Admin\QuizController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Entry points
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('admin.dashboard')
        : redirect()->route('login');
});

// Breeze redirects here after login / register / verification.
Route::get('/dashboard', fn () => redirect()->route('admin.dashboard'))
    ->middleware('auth')
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

/*
|--------------------------------------------------------------------------
| Admin panel
|--------------------------------------------------------------------------
*/

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'role_or_permission:super-admin|admin|manager|instructor'])
    ->group(function () {

        Route::get('/', [DashboardController::class, 'index'])
            ->middleware('can:dashboard.view')
            ->name('dashboard');

        /* ------------------------------ People ------------------------------ */

        Route::middleware('can:users.view')->group(function () {
            Route::get('instructors', [\App\Http\Controllers\Admin\InstructorController::class, 'index'])->name('instructors.index');
            Route::get('instructors/{instructor}', [\App\Http\Controllers\Admin\InstructorController::class, 'show'])->name('instructors.show');

            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::get('users/create', [UserController::class, 'create'])->middleware('can:users.create')->name('users.create');
            Route::post('users', [UserController::class, 'store'])->middleware('can:users.create')->name('users.store');
            Route::get('users/{user}/edit', [UserController::class, 'edit'])->middleware('can:users.update')->name('users.edit');
            Route::put('users/{user}', [UserController::class, 'update'])->middleware('can:users.update')->name('users.update');
            Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('can:users.delete')->name('users.destroy');
            Route::post('users/{user}/restore', [UserController::class, 'restore'])->middleware('can:users.restore')->withTrashed()->name('users.restore');
            Route::delete('users/{user}/force', [UserController::class, 'forceDestroy'])->middleware('can:users.delete')->withTrashed()->name('users.force-destroy');
        });

        Route::middleware('role:super-admin')->group(function () {
            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
            Route::get('roles/create', [RoleController::class, 'create'])->name('roles.create');
            Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
            Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
            Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        });

        /* ----------------------------- Learning ----------------------------- */

        Route::middleware('can:categories.view')->group(function () {
            Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
            Route::get('categories/create', [CategoryController::class, 'create'])->middleware('can:categories.create')->name('categories.create');
            Route::post('categories', [CategoryController::class, 'store'])->middleware('can:categories.create')->name('categories.store');
            Route::get('categories/{category}/edit', [CategoryController::class, 'edit'])->middleware('can:categories.update')->name('categories.edit');
            Route::put('categories/{category}', [CategoryController::class, 'update'])->middleware('can:categories.update')->name('categories.update');
            Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->middleware('can:categories.delete')->name('categories.destroy');
        });

        Route::middleware('can:courses.view')->group(function () {
            Route::get('courses', [CourseController::class, 'index'])->name('courses.index');
            Route::get('courses/create', [CourseController::class, 'create'])->middleware('can:courses.create')->name('courses.create');
            Route::post('courses', [CourseController::class, 'store'])->middleware('can:courses.create')->name('courses.store');
            Route::get('courses/{course}', [CourseController::class, 'show'])->name('courses.show');
            Route::get('courses/{course}/edit', [CourseController::class, 'edit'])->middleware('can:courses.update')->name('courses.edit');
            Route::put('courses/{course}', [CourseController::class, 'update'])->middleware('can:courses.update')->name('courses.update');
            Route::post('courses/{course}/publish', [CourseController::class, 'togglePublish'])->middleware('can:courses.update')->name('courses.publish');
            Route::delete('courses/{course}', [CourseController::class, 'destroy'])->middleware('can:courses.delete')->name('courses.destroy');
            Route::post('courses/{course}/restore', [CourseController::class, 'restore'])->middleware('can:courses.restore')->withTrashed()->name('courses.restore');
            Route::delete('courses/{course}/force', [CourseController::class, 'forceDestroy'])->middleware('can:courses.delete')->withTrashed()->name('courses.force-destroy');

            // Course builder: modules.
            Route::post('courses/{course}/modules', [ModuleController::class, 'store'])->middleware('can:courses.update')->name('modules.store');
            Route::put('modules/{module}', [ModuleController::class, 'update'])->middleware('can:courses.update')->name('modules.update');
            Route::post('modules/{module}/move', [ModuleController::class, 'move'])->middleware('can:courses.update')->name('modules.move');
            Route::delete('modules/{module}', [ModuleController::class, 'destroy'])->middleware('can:courses.update')->name('modules.destroy');

            // Course builder: lessons.
            Route::post('modules/{module}/lessons', [LessonController::class, 'store'])->middleware('can:lessons.create')->name('lessons.store');
            Route::get('lessons/{lesson}/edit', [LessonController::class, 'edit'])->middleware('can:lessons.update')->name('lessons.edit');
            Route::put('lessons/{lesson}', [LessonController::class, 'update'])->middleware('can:lessons.update')->name('lessons.update');
            Route::post('lessons/{lesson}/move', [LessonController::class, 'move'])->middleware('can:lessons.update')->name('lessons.move');
            Route::delete('lessons/{lesson}', [LessonController::class, 'destroy'])->middleware('can:lessons.delete')->name('lessons.destroy');
            Route::post('lessons/{lesson}/resources', [LessonController::class, 'storeResource'])->middleware('can:lessons.update')->name('lessons.resources.store');
            Route::delete('lessons/{lesson}/resources/{resource}', [LessonController::class, 'destroyResource'])->middleware('can:lessons.update')->name('lessons.resources.destroy');
        });

        Route::middleware('can:enrollments.view')->group(function () {
            Route::get('enrollments', [EnrollmentController::class, 'index'])->name('enrollments.index');
            Route::get('enrollments/create', [EnrollmentController::class, 'create'])->middleware('can:enrollments.create')->name('enrollments.create');
            Route::post('enrollments', [EnrollmentController::class, 'store'])->middleware('can:enrollments.create')->name('enrollments.store');
            Route::delete('enrollments/{enrollment}', [EnrollmentController::class, 'destroy'])->middleware('can:enrollments.delete')->name('enrollments.destroy');
        });

        Route::middleware('can:assignments.view')->group(function () {
            Route::get('assignments', [AssignmentController::class, 'index'])->name('assignments.index');
            Route::get('assignments/create', [AssignmentController::class, 'create'])->middleware('can:assignments.create')->name('assignments.create');
            Route::post('assignments', [AssignmentController::class, 'store'])->middleware('can:assignments.create')->name('assignments.store');
            Route::get('assignments/{assignment}/edit', [AssignmentController::class, 'edit'])->middleware('can:assignments.update')->name('assignments.edit');
            Route::put('assignments/{assignment}', [AssignmentController::class, 'update'])->middleware('can:assignments.update')->name('assignments.update');
            Route::delete('assignments/{assignment}', [AssignmentController::class, 'destroy'])->middleware('can:assignments.delete')->name('assignments.destroy');
            Route::delete('assignments/{assignment}/attachments/{attachment}', [AssignmentController::class, 'destroyAttachment'])->middleware('can:assignments.update')->name('assignments.attachments.destroy');
            Route::get('assignments/{assignment}/submissions', [AssignmentController::class, 'submissions'])->name('assignments.submissions');
            Route::post('submissions/{submission}/grade', [AssignmentController::class, 'grade'])->middleware('can:assignments.grade')->name('submissions.grade');
        });

        Route::middleware('can:quizzes.view')->group(function () {
            Route::get('quizzes', [QuizController::class, 'index'])->name('quizzes.index');
            Route::get('quizzes/create', [QuizController::class, 'create'])->middleware('can:quizzes.create')->name('quizzes.create');
            Route::post('quizzes', [QuizController::class, 'store'])->middleware('can:quizzes.create')->name('quizzes.store');
            Route::get('quizzes/{quiz}', [QuizController::class, 'show'])->name('quizzes.show');
            Route::get('quizzes/{quiz}/attempts', [QuizController::class, 'attempts'])->name('quizzes.attempts');
            Route::get('quizzes/{quiz}/edit', [QuizController::class, 'edit'])->middleware('can:quizzes.update')->name('quizzes.edit');
            Route::put('quizzes/{quiz}', [QuizController::class, 'update'])->middleware('can:quizzes.update')->name('quizzes.update');
            Route::delete('quizzes/{quiz}', [QuizController::class, 'destroy'])->middleware('can:quizzes.delete')->name('quizzes.destroy');

            Route::post('quizzes/{quiz}/questions', [QuestionController::class, 'store'])->middleware('can:quizzes.update')->name('questions.store');
            Route::put('questions/{question}', [QuestionController::class, 'update'])->middleware('can:quizzes.update')->name('questions.update');
            Route::delete('questions/{question}', [QuestionController::class, 'destroy'])->middleware('can:quizzes.update')->name('questions.destroy');
        });

        Route::middleware('can:attendance.view')->group(function () {
            Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
            Route::get('attendance/log', [AttendanceController::class, 'log'])->name('attendance.log');
            Route::post('attendance', [AttendanceController::class, 'save'])->middleware('can:attendance.manage')->name('attendance.save');

            // Biometric devices & punch logs (infrastructure — not for instructors)
            Route::get('biometric/devices', [\App\Http\Controllers\Admin\BiometricController::class, 'devices'])->middleware('can:devices.view')->name('biometric.devices');
            Route::get('biometric/punches', [\App\Http\Controllers\Admin\BiometricController::class, 'punches'])->middleware('can:devices.view')->name('biometric.punches');
            Route::middleware('can:devices.manage')->group(function () {
                Route::post('biometric/devices', [\App\Http\Controllers\Admin\BiometricController::class, 'storeDevice'])->name('biometric.devices.store');
                Route::put('biometric/devices/{device}', [\App\Http\Controllers\Admin\BiometricController::class, 'updateDevice'])->name('biometric.devices.update');
                Route::post('biometric/devices/{device}/key', [\App\Http\Controllers\Admin\BiometricController::class, 'regenerateKey'])->name('biometric.devices.key');
                Route::post('biometric/devices/{device}/reprocess', [\App\Http\Controllers\Admin\BiometricController::class, 'reprocess'])->name('biometric.devices.reprocess');
                Route::delete('biometric/devices/{device}', [\App\Http\Controllers\Admin\BiometricController::class, 'destroyDevice'])->name('biometric.devices.destroy');
                Route::post('biometric/punches/import', [\App\Http\Controllers\Admin\BiometricController::class, 'import'])->name('biometric.punches.import');
            });
        });

        Route::middleware('can:certificates.view')->group(function () {
            Route::get('certificates', [CertificateController::class, 'index'])->name('certificates.index');
            Route::get('certificates/create', [CertificateController::class, 'create'])->middleware('can:certificates.issue')->name('certificates.create');
            Route::post('certificates', [CertificateController::class, 'store'])->middleware('can:certificates.issue')->name('certificates.store');
            Route::delete('certificates/{certificate}', [CertificateController::class, 'destroy'])->middleware('can:certificates.delete')->name('certificates.destroy');
        });

        /* ---------------------------- Engagement ----------------------------- */

        Route::middleware('can:announcements.view')->group(function () {
            Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
            Route::get('announcements/create', [AnnouncementController::class, 'create'])->middleware('can:announcements.create')->name('announcements.create');
            Route::post('announcements', [AnnouncementController::class, 'store'])->middleware('can:announcements.create')->name('announcements.store');
            Route::get('announcements/{announcement}/edit', [AnnouncementController::class, 'edit'])->middleware('can:announcements.update')->name('announcements.edit');
            Route::put('announcements/{announcement}', [AnnouncementController::class, 'update'])->middleware('can:announcements.update')->name('announcements.update');
            Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy'])->middleware('can:announcements.delete')->name('announcements.destroy');
        });

        Route::middleware('can:help.view')->group(function () {
            Route::get('help', [HelpController::class, 'index'])->name('help.index');

            Route::middleware('can:help.manage')->group(function () {
                Route::post('help/categories', [HelpController::class, 'storeCategory'])->name('help.categories.store');
                Route::put('help/categories/{category}', [HelpController::class, 'updateCategory'])->name('help.categories.update');
                Route::delete('help/categories/{category}', [HelpController::class, 'destroyCategory'])->name('help.categories.destroy');

                Route::get('help/articles/create', [HelpController::class, 'createArticle'])->name('help.articles.create');
                Route::post('help/articles', [HelpController::class, 'storeArticle'])->name('help.articles.store');
                Route::get('help/articles/{article}/edit', [HelpController::class, 'editArticle'])->name('help.articles.edit');
                Route::put('help/articles/{article}', [HelpController::class, 'updateArticle'])->name('help.articles.update');
                Route::delete('help/articles/{article}', [HelpController::class, 'destroyArticle'])->name('help.articles.destroy');

                Route::post('help/faqs', [HelpController::class, 'storeFaq'])->name('help.faqs.store');
                Route::put('help/faqs/{faq}', [HelpController::class, 'updateFaq'])->name('help.faqs.update');
                Route::delete('help/faqs/{faq}', [HelpController::class, 'destroyFaq'])->name('help.faqs.destroy');
            });
        });

        /* ------------------------------ Finance ------------------------------ */

        Route::middleware('can:billing.view')->group(function () {
            Route::get('billing/plans', [BillingController::class, 'plans'])->name('billing.plans.index');
            Route::get('billing/plans/create', [BillingController::class, 'createPlan'])->middleware('can:billing.manage')->name('billing.plans.create');
            Route::post('billing/plans', [BillingController::class, 'storePlan'])->middleware('can:billing.manage')->name('billing.plans.store');
            Route::get('billing/plans/{plan}/edit', [BillingController::class, 'editPlan'])->middleware('can:billing.manage')->name('billing.plans.edit');
            Route::put('billing/plans/{plan}', [BillingController::class, 'updatePlan'])->middleware('can:billing.manage')->name('billing.plans.update');

            Route::get('billing/invoices', [BillingController::class, 'invoices'])->name('billing.invoices.index');
            Route::get('billing/invoices/create', [BillingController::class, 'createInvoice'])->middleware('can:billing.manage')->name('billing.invoices.create');
            Route::post('billing/invoices', [BillingController::class, 'storeInvoice'])->middleware('can:billing.manage')->name('billing.invoices.store');
            Route::get('billing/invoices/{invoice}', [BillingController::class, 'showInvoice'])->name('billing.invoices.show');
            Route::post('billing/invoices/{invoice}/void', [BillingController::class, 'voidInvoice'])->middleware('can:billing.manage')->name('billing.invoices.void');
            Route::post('billing/invoices/{invoice}/payments', [BillingController::class, 'recordPayment'])->middleware('can:billing.manage')->name('billing.invoices.payments.store');
            Route::get('billing/submissions', [BillingController::class, 'submissions'])->name('billing.submissions');
            Route::post('billing/submissions/{transaction}/approve', [BillingController::class, 'approveSubmission'])->middleware('can:billing.manage')->name('billing.submissions.approve');
            Route::post('billing/submissions/{transaction}/reject', [BillingController::class, 'rejectSubmission'])->middleware('can:billing.manage')->name('billing.submissions.reject');
            Route::get('billing/transactions', [BillingController::class, 'transactions'])->name('billing.transactions.index');
        });

        // Topbar bell — available to every panel user.
        Route::post('notifications/read-all', function () {
            auth()->user()->unreadNotifications->markAsRead();

            return back();
        })->name('notifications.read-all');

        /* ------------------------------ System ------------------------------- */

        Route::middleware('can:audit-logs.view')->group(function () {
            Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
            Route::get('audit-logs/export', [AuditLogController::class, 'export'])->middleware('can:audit-logs.export')->name('audit-logs.export');
        });

        Route::middleware('can:reports.view')->group(function () {
            Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
            Route::get('reports/{report}/export', [ReportController::class, 'export'])->middleware('can:reports.export')->name('reports.export');
        });

        Route::middleware('can:settings.view')->group(function () {
            Route::get('settings', [SettingController::class, 'edit'])->name('settings.edit');
            Route::put('settings', [SettingController::class, 'update'])->middleware('can:settings.update')->name('settings.update');
            Route::post('settings/backups/run', [SettingController::class, 'runBackup'])->middleware('can:backups.run')->name('settings.backups.run');
        });

        Route::middleware('can:media.view')->group(function () {
            Route::get('media', [MediaController::class, 'index'])->name('media.index');
            Route::post('media', [MediaController::class, 'store'])->middleware('can:media.upload')->name('media.store');
            Route::delete('media', [MediaController::class, 'destroy'])->middleware('can:media.delete')->name('media.destroy');
        });
    });

require __DIR__.'/auth.php';
