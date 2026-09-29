<?php

use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\Admin\AssignmentController;
use App\Http\Controllers\Admin\AttendanceSlotController;
use App\Http\Controllers\Admin\HolidayController;
use App\Http\Controllers\Admin\RuleTemplateController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\PaymentMethodController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CertificateController;
use App\Http\Controllers\Admin\CourseController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EnrollmentController;
use App\Http\Controllers\Admin\HelpController;
use App\Http\Controllers\Admin\LessonController;
use App\Http\Controllers\Admin\ModuleController;
use App\Http\Controllers\Admin\QuestionController;
use App\Http\Controllers\Admin\QuizController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\TaskStatusController;
use App\Http\Controllers\Admin\ProjectStatusController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\ProjectController;
use App\Http\Controllers\Admin\ProjectMilestoneController;
use App\Http\Controllers\Admin\TaskAssignmentController;
use App\Http\Controllers\Admin\TaskBoardController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\Admin\TeamAttendanceController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Admin\TeamFineController;
use App\Http\Controllers\Admin\TeamLeaveController;
use App\Http\Controllers\Admin\TeamScoreController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\NoteController;
use App\Http\Controllers\Admin\PrivateNoteController;
use App\Support\PortalHome;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Entry points
|--------------------------------------------------------------------------
*/

/*
 * Both of these ask PortalHome where the arriving person belongs rather than
 * naming a screen, because the answer differs per role and will differ again
 * every phase. It returns the login route for a guest, so this is one
 * expression rather than two.
 */
Route::get('/', fn () => redirect()->route(PortalHome::for(auth()->user())));

/*
 * Breeze redirects here after login, registration, email verification, the
 * verification prompt, resending the verification mail and confirming a
 * password — all seven of its controllers call route('dashboard'), and the
 * framework's own guest middleware resolves to the same name when an
 * already-authenticated visitor opens /login. Fixing the NAME therefore fixes
 * every one of those at once, which is why Breeze itself is untouched.
 */
Route::get('/dashboard', fn () => redirect()->route(PortalHome::for(auth()->user())))
    ->middleware('auth')
    ->name('dashboard');

/*
 * Signed in, with no screens yet: a client today, and any role a later phase
 * adds before the phase that builds its portal.
 *
 * A page, and a 200. A 403 at the end of a successful login looks like a
 * broken account and turns into a support message; this says what is actually
 * true, which is that the account works and an administrator has to finish
 * setting it up.
 */
Route::get('/no-portal', fn () => view('portal.unavailable'))
    ->middleware('auth')
    ->name(PortalHome::NONE);

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

/*
 * `dashboard.view` sits in this list beside the role names, and is not
 * decoration: role_or_permission takes either, and without it a custom role
 * built on the Roles & Permissions screen holding only that permission is
 * refused at this door — PortalHome sends them to the dashboard because the
 * route exists, and they get the 403 the resolver was written to remove.
 * Every role that already held `dashboard.view` is named here anyway, so this
 * admits nobody new but the roles a super-admin deliberately built.
 */
Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'role_or_permission:super-admin|admin|manager|instructor|dashboard.view'])
    ->group(function () {

        Route::get('/', [DashboardController::class, 'index'])
            ->middleware('can:dashboard.view')
            ->name('dashboard');

        /* ------------------------------ People ------------------------------ */

        Route::middleware('can:users.view')->group(function () {
            Route::get('instructors', [\App\Http\Controllers\Admin\InstructorController::class, 'index'])->name('instructors.index');
            Route::get('instructors/{instructor}', [\App\Http\Controllers\Admin\InstructorController::class, 'show'])->name('instructors.show');
        });


        Route::middleware('can:students.view')->group(function () {
            Route::get('students', [\App\Http\Controllers\Admin\StudentController::class, 'index'])->name('students.index');
            Route::get('students/register', [\App\Http\Controllers\Admin\StudentController::class, 'create'])->middleware('can:students.create')->name('students.create');
            Route::post('students', [\App\Http\Controllers\Admin\StudentController::class, 'store'])->middleware('can:students.create')->name('students.store');
            Route::get('students/{student}', [\App\Http\Controllers\Admin\StudentController::class, 'show'])->name('students.show');
            Route::get('students/{student}/edit', [\App\Http\Controllers\Admin\StudentController::class, 'edit'])->middleware('can:students.update')->name('students.edit');
            Route::put('students/{student}', [\App\Http\Controllers\Admin\StudentController::class, 'update'])->middleware('can:students.update')->name('students.update');
            Route::delete('students/{student}', [\App\Http\Controllers\Admin\StudentController::class, 'destroy'])->middleware('can:students.delete')->name('students.destroy');
            Route::post('students/{student}/restore', [\App\Http\Controllers\Admin\StudentController::class, 'restore'])->middleware('can:students.delete')->withTrashed()->name('students.restore');
            Route::delete('students/{student}/force', [\App\Http\Controllers\Admin\StudentController::class, 'forceDestroy'])->middleware('can:students.delete')->withTrashed()->name('students.force-destroy');

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
            // Course-level resources. Gated on courses.update, matching every
            // other edit to the course itself; no new permission is needed
            // because "may edit this course" is exactly the question.
            Route::post('courses/{course}/resources', [CourseController::class, 'storeResource'])->middleware('can:courses.update')->name('courses.resources.store');
            Route::delete('courses/{course}/resources/{resource}', [CourseController::class, 'destroyResource'])->middleware('can:courses.update')->name('courses.resources.destroy');
            Route::post('lessons/{lesson}/resources', [LessonController::class, 'storeResource'])->middleware('can:lessons.update')->name('lessons.resources.store');
            Route::delete('lessons/{lesson}/resources/{resource}', [LessonController::class, 'destroyResource'])->middleware('can:lessons.update')->name('lessons.resources.destroy');
        });

        Route::middleware('can:notes.view')->group(function () {
            Route::get('notes', [NoteController::class, 'index'])->name('notes.index');
            Route::get('notes/create', [NoteController::class, 'create'])
                ->middleware('can:notes.create')
                ->name('notes.create');
            Route::post('notes', [NoteController::class, 'store'])
                ->middleware('can:notes.create')
                ->name('notes.store');
            Route::get('notes/{note}/edit', [NoteController::class, 'edit'])
                ->middleware('can:notes.update')
                ->name('notes.edit');
            Route::put('notes/{note}', [NoteController::class, 'update'])
                ->middleware('can:notes.update')
                ->name('notes.update');
            Route::get('notes/{note}/download', [NoteController::class, 'download'])
                ->name('notes.download');
            Route::delete('notes/{note}', [NoteController::class, 'destroy'])
                ->middleware('can:notes.delete')
                ->name('notes.destroy');
            Route::post('/notes/{note}/read', [NoteController::class, 'read']);
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
            Route::post('submissions/{submission}/return', [AssignmentController::class, 'returnForChanges'])->middleware('can:assignments.grade')->name('submissions.return');
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

        // Either permission opens these screens; which of the two the user
        // holds is what the controllers read to decide how much they see.
        // Spatie's `permission` middleware treats the pipe as OR.
        Route::middleware('permission:attendance.daily|attendance.daily.own-category')->group(function () {
            Route::get('attendance/daily', [\App\Http\Controllers\Admin\DailyAttendanceController::class, 'index'])->name('attendance.daily');
            Route::get('attendance/daily/print', [\App\Http\Controllers\Admin\DailyAttendanceController::class, 'print'])->name('attendance.daily.print');
            Route::post('attendance/daily', [\App\Http\Controllers\Admin\DailyAttendanceController::class, 'mark'])->name('attendance.daily.mark');
            Route::post('attendance/daily/bulk-present', [\App\Http\Controllers\Admin\DailyAttendanceController::class, 'bulkPresent'])->name('attendance.daily.bulk');
            Route::get('attendance/daily/{student}', [\App\Http\Controllers\Admin\DailyAttendanceController::class, 'show'])->name('attendance.daily.show');
            Route::get('attendance/daily/{student}/print', [\App\Http\Controllers\Admin\DailyAttendanceController::class, 'printStudent'])->name('attendance.daily.show-print');
            Route::put('attendance/daily/{record}', [\App\Http\Controllers\Admin\DailyAttendanceController::class, 'update'])->name('attendance.daily.update');

            Route::get('leaves', [\App\Http\Controllers\Admin\LeaveApplicationController::class, 'index'])->name('leaves.index');
            Route::post('leaves/{leave}/review', [\App\Http\Controllers\Admin\LeaveApplicationController::class, 'review'])->name('leaves.review');
        });

        // Biometric devices & punch logs (infrastructure — not for instructors).
        // These sat inside the retired Class Attendance group and carry their
        // own device permissions, which were always the ones that mattered.
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
            Route::get('billing/plans/{plan}', [BillingController::class, 'showPlan'])->whereNumber('plan')->name('billing.plans.show');
            Route::get('billing/plans/{plan}/edit', [BillingController::class, 'editPlan'])->middleware('can:billing.manage')->name('billing.plans.edit');
            Route::put('billing/plans/{plan}', [BillingController::class, 'updatePlan'])->middleware('can:billing.manage')->name('billing.plans.update');

            Route::get('billing/invoices', [BillingController::class, 'invoices'])->name('billing.invoices.index');
            Route::get('billing/invoices/create', [BillingController::class, 'createInvoice'])->middleware('can:billing.manage')->name('billing.invoices.create');
            Route::post('billing/invoices', [BillingController::class, 'storeInvoice'])->middleware('can:billing.manage')->name('billing.invoices.store');
            Route::get('billing/invoices/{invoice}', [BillingController::class, 'showInvoice'])->name('billing.invoices.show');
            Route::post('billing/invoices/{invoice}/void', [BillingController::class, 'voidInvoice'])->middleware('can:billing.manage')->name('billing.invoices.void');
            Route::post('billing/invoices/{invoice}/adjust', [BillingController::class, 'adjustInvoiceAmount'])->middleware('can:billing.manage')->name('billing.invoices.adjust');

            Route::get('billing/payment-methods', [PaymentMethodController::class, 'index'])->name('billing.payment-methods.index');
            Route::get('billing/payment-methods/create', [PaymentMethodController::class, 'create'])->middleware('can:billing.manage')->name('billing.payment-methods.create');
            Route::post('billing/payment-methods', [PaymentMethodController::class, 'store'])->middleware('can:billing.manage')->name('billing.payment-methods.store');
            Route::get('billing/payment-methods/{paymentMethod}/edit', [PaymentMethodController::class, 'edit'])->middleware('can:billing.manage')->name('billing.payment-methods.edit');
            Route::put('billing/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'update'])->middleware('can:billing.manage')->name('billing.payment-methods.update');
            Route::delete('billing/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy'])->middleware('can:billing.manage')->name('billing.payment-methods.destroy');
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

        /*
         * Student private notes, read-only, super-admin only.
         *
         * Two GET routes and nothing else: no store, no update, no destroy.
         * A super-admin may look at a note and may not change it, and that is
         * enforced by the verbs not existing rather than by a policy someone
         * could later widen. The gate is a role check — see AppServiceProvider
         * for why it is not a grantable permission.
         */
        Route::middleware('can:private-notes.read')->group(function () {
            Route::get('private-notes', [PrivateNoteController::class, 'index'])->name('private-notes.index');
            Route::get('private-notes/{note}', [PrivateNoteController::class, 'show'])->name('private-notes.show');
        });

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

            // The slots students are admitted into, which decide when each of
            // them counts as late. Part of Settings, so it rides the same gate.
            Route::get('settings/attendance-slots', [AttendanceSlotController::class, 'index'])->name('attendance-slots.index');
            Route::middleware('can:settings.update')->group(function () {
                Route::get('settings/attendance-slots/create', [AttendanceSlotController::class, 'create'])->name('attendance-slots.create');
                Route::post('settings/attendance-slots', [AttendanceSlotController::class, 'store'])->name('attendance-slots.store');
                Route::get('settings/attendance-slots/{attendanceSlot}/edit', [AttendanceSlotController::class, 'edit'])->name('attendance-slots.edit');
                Route::put('settings/attendance-slots/{attendanceSlot}', [AttendanceSlotController::class, 'update'])->name('attendance-slots.update');
                Route::post('settings/attendance-slots/{attendanceSlot}/toggle', [AttendanceSlotController::class, 'toggle'])->name('attendance-slots.toggle');
                Route::post('settings/attendance-slots/{attendanceSlot}/move', [AttendanceSlotController::class, 'move'])->name('attendance-slots.move');
                Route::delete('settings/attendance-slots/{attendanceSlot}', [AttendanceSlotController::class, 'destroy'])->name('attendance-slots.destroy');
            });

            // Dates the academy is closed. Weekly closures are the
            // `academy_working_days` setting above; these are the ones that
            // move — Eid, 14 August — and they close the academy for every
            // slot, so they live beside the slots and ride the same gate.
            Route::get('settings/holidays', [HolidayController::class, 'index'])->name('holidays.index');
            Route::middleware('can:settings.update')->group(function () {
                Route::get('settings/holidays/create', [HolidayController::class, 'create'])->name('holidays.create');
                Route::post('settings/holidays', [HolidayController::class, 'store'])->name('holidays.store');
                Route::get('settings/holidays/{holiday}/edit', [HolidayController::class, 'edit'])->name('holidays.edit');
                Route::put('settings/holidays/{holiday}', [HolidayController::class, 'update'])->name('holidays.update');
                Route::delete('settings/holidays/{holiday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');
            });

            /*
             * The team portal's two configurable status lists.
             *
             * Settings screens, but NOT on the settings gate: they carry their
             * own permission, so an academy manager who may change the day
             * start does not thereby decide what a client project can be. The
             * two lists are two permissions for the same reason — two tables,
             * two behaviour sets, no reason one implies the other.
             */
            Route::middleware('can:task-statuses.manage')->group(function () {
                Route::get('settings/task-statuses', [TaskStatusController::class, 'index'])->name('task-statuses.index');
                Route::get('settings/task-statuses/create', [TaskStatusController::class, 'create'])->name('task-statuses.create');
                Route::post('settings/task-statuses', [TaskStatusController::class, 'store'])->name('task-statuses.store');
                Route::get('settings/task-statuses/{status}/edit', [TaskStatusController::class, 'edit'])->name('task-statuses.edit');
                Route::put('settings/task-statuses/{status}', [TaskStatusController::class, 'update'])->name('task-statuses.update');
                Route::post('settings/task-statuses/{status}/toggle', [TaskStatusController::class, 'toggle'])->name('task-statuses.toggle');
                Route::post('settings/task-statuses/{status}/move', [TaskStatusController::class, 'move'])->name('task-statuses.move');
                Route::delete('settings/task-statuses/{status}', [TaskStatusController::class, 'destroy'])->name('task-statuses.destroy');
            });

            Route::middleware('can:project-statuses.manage')->group(function () {
                Route::get('settings/project-statuses', [ProjectStatusController::class, 'index'])->name('project-statuses.index');
                Route::get('settings/project-statuses/create', [ProjectStatusController::class, 'create'])->name('project-statuses.create');
                Route::post('settings/project-statuses', [ProjectStatusController::class, 'store'])->name('project-statuses.store');
                Route::get('settings/project-statuses/{status}/edit', [ProjectStatusController::class, 'edit'])->name('project-statuses.edit');
                Route::put('settings/project-statuses/{status}', [ProjectStatusController::class, 'update'])->name('project-statuses.update');
                Route::post('settings/project-statuses/{status}/toggle', [ProjectStatusController::class, 'toggle'])->name('project-statuses.toggle');
                Route::post('settings/project-statuses/{status}/move', [ProjectStatusController::class, 'move'])->name('project-statuses.move');
                Route::delete('settings/project-statuses/{status}', [ProjectStatusController::class, 'destroy'])->name('project-statuses.destroy');
            });

            // The wording of the student Rules page. The numbers on it come
            // from the settings above; only the sentences are edited here.
            Route::get('settings/rules', [RuleTemplateController::class, 'index'])->name('rules.index');
            Route::middleware('can:settings.update')->group(function () {
                Route::put('settings/rules/{rule}', [RuleTemplateController::class, 'update'])->name('rules.update');
                Route::post('settings/rules/{rule}/reset', [RuleTemplateController::class, 'reset'])->name('rules.reset');
            });
        });
    });

/*
|--------------------------------------------------------------------------
| Team portal
|--------------------------------------------------------------------------
|
| MarkDev's own project management, for MarkDev's own staff. Same prefix, same
| `admin.` route names, same layout and sidebar as the panel above — these are
| staff screens and the staff already sign in here.
|
| A SEPARATE GROUP, because the gate is different. The academy group admits
| super-admin, admin, manager and instructor; this one admits super-admin, admin
| and the two team roles. That is what makes the separation structural rather
| than a matter of every future route remembering its `can:`: a team lead is
| refused at the door of every academy screen, and an instructor at the door of
| every team screen, whatever permissions get added later.
|
| Manager is absent on purpose. Managers run the academy, not client work.
|
| The three permissions beside the role names are there for the same reason as
| `dashboard.view` on the academy group: holding `teams.view` has to be enough
| to open the team door, because a super-admin who granted it meant it, and a
| custom role holding it is in none of the role names above. They are every
| permission PortalHome can send someone here for, listed now so the phase that
| ships the projects and tasks screens inherits the gate rather than debugging
| it. No academy role holds any of them — TeamRoleSeparationTest asserts that in
| both directions — so widening the door does not widen who comes through it.
*/
Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'role_or_permission:super-admin|admin|team-lead|team|teams.view|projects.view|tasks.view|clients.view'])
    ->group(function () {

        Route::middleware('can:teams.view')->group(function () {
            Route::get('teams', [TeamController::class, 'index'])->name('teams.index');
            Route::get('teams/create', [TeamController::class, 'create'])->middleware('can:teams.create')->name('teams.create');
            Route::post('teams', [TeamController::class, 'store'])->middleware('can:teams.create')->name('teams.store');
            Route::get('teams/{team}/edit', [TeamController::class, 'edit'])->middleware('can:teams.update')->name('teams.edit');
            Route::put('teams/{team}', [TeamController::class, 'update'])->middleware('can:teams.update')->name('teams.update');
            Route::post('teams/{team}/toggle', [TeamController::class, 'toggle'])->middleware('can:teams.update')->name('teams.toggle');
            Route::delete('teams/{team}', [TeamController::class, 'destroy'])->middleware('can:teams.delete')->name('teams.destroy');

            // A LIST screen, so it reads the cached figures. A member does not
            // hold teams.view and never reaches it: somebody else's score is
            // somebody else's business.
            Route::get('teams/{team}/scores', [TeamScoreController::class, 'show'])->name('teams.scores');
            Route::post('teams/{team}/scores/refresh', [TeamScoreController::class, 'refresh'])->name('teams.scores.refresh');
        });

        /*
         * Clients. `clients.*` is held by super-admin and admin only, and there
         * is deliberately no scoped version of any of these: a team person has
         * no business knowing who a project is for, so the answer is no screen
         * rather than a narrower one.
         */
        Route::middleware('can:clients.view')->group(function () {
            Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
            Route::get('clients/create', [ClientController::class, 'create'])->middleware('can:clients.create')->name('clients.create');
            Route::post('clients', [ClientController::class, 'store'])->middleware('can:clients.create')->name('clients.store');
            Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');
            Route::get('clients/{client}/edit', [ClientController::class, 'edit'])->middleware('can:clients.update')->name('clients.edit');
            Route::put('clients/{client}', [ClientController::class, 'update'])->middleware('can:clients.update')->name('clients.update');
            Route::post('clients/{client}/toggle', [ClientController::class, 'toggle'])->middleware('can:clients.update')->name('clients.toggle');
            Route::delete('clients/{client}', [ClientController::class, 'destroy'])->middleware('can:clients.delete')->name('clients.destroy');
        });

        /*
         * Projects. The two SHARED screens in this phase are the index and the
         * project page: a team person reaches both, narrowed to the teams they
         * are a member of, with the client and the money gated out. Everything
         * that writes carries `projects.create/update/delete`, which only
         * super-admin and admin hold — so no team person ever opens a form that
         * would have to hide half of itself.
         */
        Route::middleware('can:projects.view')->group(function () {
            Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
            // The forms also require `clients.view`. A project must have a
            // client, so the form has to offer the client list — there is no
            // version of this screen that both works and hides it. Rather than
            // half-render it, the door asks for both: if you may set up a
            // project you may see who it is for.
            Route::get('projects/create', [ProjectController::class, 'create'])->middleware(['can:projects.create', 'can:clients.view'])->name('projects.create');
            Route::post('projects', [ProjectController::class, 'store'])->middleware(['can:projects.create', 'can:clients.view'])->name('projects.store');
            Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
            Route::get('projects/{project}/edit', [ProjectController::class, 'edit'])->middleware(['can:projects.update', 'can:clients.view'])->name('projects.edit');
            Route::put('projects/{project}', [ProjectController::class, 'update'])->middleware(['can:projects.update', 'can:clients.view'])->name('projects.update');
            Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->middleware('can:projects.delete')->name('projects.destroy');

            // Milestones belong to their project and are addressed through it,
            // so a milestone id from somebody else's project is a 404 rather
            // than an edit.
            Route::middleware('can:projects.update')->group(function () {
                Route::get('projects/{project}/milestones/create', [ProjectMilestoneController::class, 'create'])->name('projects.milestones.create');
                Route::post('projects/{project}/milestones', [ProjectMilestoneController::class, 'store'])->name('projects.milestones.store');
                Route::get('projects/{project}/milestones/{milestone}/edit', [ProjectMilestoneController::class, 'edit'])->name('projects.milestones.edit');
                Route::put('projects/{project}/milestones/{milestone}', [ProjectMilestoneController::class, 'update'])->name('projects.milestones.update');
                Route::post('projects/{project}/milestones/{milestone}/move', [ProjectMilestoneController::class, 'move'])->name('projects.milestones.move');
                Route::delete('projects/{project}/milestones/{milestone}', [ProjectMilestoneController::class, 'destroy'])->name('projects.milestones.destroy');
            });
        });

        /*
         * Tasks, and the board.
         *
         * TWO PERMISSIONS, TWO JOBS. `tasks.create` DEFINES work — the form,
         * the allowance, who holds it — and is held by leads and admins.
         * `tasks.update` MOVES work along and is held by members too, on
         * purpose: a member must be able to say they are blocked, and must not
         * be able to rewrite the promise their score is measured against.
         *
         * `board` and `create` are declared before `{task}` so they are not
         * swallowed as an id.
         */
        Route::middleware('can:tasks.view')->group(function () {
            Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
            Route::get('tasks/board', [TaskBoardController::class, 'index'])->name('tasks.board');
            Route::get('tasks/create', [TaskController::class, 'create'])->middleware('can:tasks.create')->name('tasks.create');
            Route::post('tasks', [TaskController::class, 'store'])->middleware('can:tasks.create')->name('tasks.store');
            Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
            Route::get('tasks/{task}/edit', [TaskController::class, 'edit'])->middleware('can:tasks.create')->name('tasks.edit');
            Route::put('tasks/{task}', [TaskController::class, 'update'])->middleware('can:tasks.create')->name('tasks.update');
            Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->middleware('can:tasks.delete')->name('tasks.destroy');

            // The board's drop and the page's picker, one endpoint.
            Route::post('tasks/{task}/move', [TaskController::class, 'move'])->middleware('can:tasks.update')->name('tasks.move');

            Route::get('tasks/{task}/assign', [TaskAssignmentController::class, 'create'])->middleware('can:tasks.create')->name('tasks.assign');
            Route::post('tasks/{task}/assign', [TaskAssignmentController::class, 'store'])->middleware('can:tasks.create')->name('tasks.assign.store');
        });

        /*
         * Attendance, leave and fines for staff.
         *
         * `tasks.view` is the whole-portal gate — every team role holds it — so
         * the three "my own" screens sit on it. `teams.view` separates a lead
         * from a member and gates the marking screen. `clients.view` is the
         * admin gate, and it is what reviewing leave and seeing anybody's
         * ledger sit behind.
         */
        Route::middleware('can:tasks.view')->group(function () {
            Route::get('my/attendance', [TeamAttendanceController::class, 'mine'])->name('team-attendance.mine');
            Route::get('my/leave', [TeamLeaveController::class, 'mine'])->name('team-leave.mine');
            Route::post('my/leave', [TeamLeaveController::class, 'store'])->name('team-leave.store');
            Route::get('my/fines', [TeamFineController::class, 'mine'])->name('team-fines.mine');

            // Somebody's ledger by id. Your own always; anybody's only with the
            // admin gate — and a 404 rather than a 403 otherwise, decided in
            // the controller so the refusal does not confirm what it refuses.
            Route::get('team/fines/{user}', [TeamFineController::class, 'show'])->name('team-fines.show');
        });

        // Marking the register: leads and admins. A member marks nobody.
        Route::middleware('can:teams.view')->group(function () {
            Route::get('team/attendance', [TeamAttendanceController::class, 'index'])->name('team-attendance.index');
            Route::post('team/attendance', [TeamAttendanceController::class, 'store'])->name('team-attendance.store');
        });

        /*
         * Reviewing leave and the whole ledger — ADMIN ONLY.
         *
         * A team-lead is deliberately absent. Pay and attendance are not a
         * lead's job here, and a lead who is scored on their team's delivery
         * should not be the one deciding whether that team gets time off.
         */
        Route::middleware('can:clients.view')->group(function () {
            Route::get('team/leave', [TeamLeaveController::class, 'index'])->name('team-leave.index');
            Route::post('team/leave/{leave}/review', [TeamLeaveController::class, 'review'])->name('team-leave.review');
            Route::get('team/fines', [TeamFineController::class, 'index'])->name('team-fines.index');
            Route::post('team/fines/{fine}/settle', [TeamFineController::class, 'settle'])->name('team-fines.settle');
        });
    });

/*
|--------------------------------------------------------------------------
| Private files
|--------------------------------------------------------------------------
|
| The only way to read an upload on the private disk. Reachable two ways and
| authorised identically by both: the admin panel arrives with a session
| cookie, the portal follows a signed link carrying the viewer's id because a
| bearer token cannot ride on an <img src> or an <a href>. ResolveFileViewer
| settles which, and FileController asks whether that person may see that file.
|
| Outside the admin group on purpose — a student has no business in /admin, and
| these are as much the portal's routes as the panel's.
*/
Route::middleware(\App\Http\Middleware\ResolveFileViewer::class)
    ->prefix('files')
    ->name('files.')
    ->group(function () {
        Route::get('students/{profile}/{kind}', [FileController::class, 'studentDocument'])->name('student-document');
        Route::get('submissions/{submission}', [FileController::class, 'submission'])->name('submission');
        Route::get('attachments/{attachment}', [FileController::class, 'attachment'])->name('attachment');
        Route::get('receipts/{transaction}', [FileController::class, 'receipt'])->name('receipt');
        Route::get('notes/{note}', [FileController::class, 'note'])->name('note');
        Route::get('resources/{resource}', [FileController::class, 'resource'])->name('resource');
    });

require __DIR__ . '/auth.php';
