<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AttendanceRecord;
use App\Models\Bookmark;
use App\Models\CalendarEvent;
use App\Models\Category;
use App\Models\Certificate;
use App\Models\Comment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Faq;
use App\Models\FeePlan;
use App\Models\HelpArticle;
use App\Models\HelpCategory;
use App\Models\Invoice;
use App\Models\LearningActivity;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Module;
use App\Models\PointEvent;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\Video;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Rich demo content so every portal and admin screen has real data. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        /* ------------------------------- People ------------------------------- */

        $instructor = User::factory()->create([
            'name' => 'Mark Instructor',
            'email' => 'instructor@markdev.test',
            'headline' => 'Principal Engineer & Lead Instructor',
            'bio' => 'Full-stack engineer with 12 years of shipping production systems.',
        ]);
        $instructor->assignRole('instructor');

        $instructor2 = User::factory()->create([
            'name' => 'Ayesha Instructor',
            'email' => 'instructor2@markdev.test',
            'headline' => 'Frontend Architect',
        ]);
        $instructor2->assignRole('instructor');

        $student = User::factory()->create([
            'name' => 'Shahzad Student',
            'email' => 'student@markdev.test',
            'headline' => 'Full-stack learner',
            'biometric_id' => '1001',
            'points' => 1240,
        ]);
        $student->assignRole('student');

        $peers = collect(range(1, 6))->map(function (int $i) {
            $peer = User::factory()->create([
                'name' => fake()->name(),
                'email' => "student{$i}@markdev.test",
                'points' => fake()->numberBetween(100, 2000),
            ]);
            $peer->assignRole('student');

            return $peer;
        });

        UserSetting::create([
            'user_id' => $student->id,
            'timezone' => 'UTC',
            'language' => 'en',
            'notifications' => [
                'email_announcements' => true,
                'email_assignment_graded' => true,
                'email_due_reminders' => true,
                'email_new_content' => false,
                'push_announcements' => true,
                'push_due_reminders' => true,
            ],
        ]);

        /* ------------------------------- Catalog ------------------------------- */

        $backend = Category::create(['name' => 'Backend', 'slug' => 'backend', 'description' => 'Server-side engineering.']);
        $frontend = Category::create(['name' => 'Frontend', 'slug' => 'frontend', 'description' => 'Modern UI engineering.']);

        $laravel = $this->course($backend, $instructor, 'Advanced Web Development with Laravel 12', 'intermediate', false, 49.00, [
            'Getting productive' => [
                ['Welcome to the course', 'video', 8, true],
                ['Environment setup', 'video', 14, true],
                ['Project architecture tour', 'article', 10, false],
            ],
            'Eloquent in depth' => [
                ['Models and relationships', 'video', 22, false],
                ['Query performance', 'video', 18, false],
                ['Eloquent knowledge check', 'quiz', 10, false],
            ],
            'Building the API' => [
                ['REST resources and versioning', 'video', 20, false],
                ['Sanctum authentication', 'video', 16, false],
                ['Ship a mini API', 'assignment', 30, false],
            ],
        ]);

        $react = $this->course($frontend, $instructor2, 'React 19 Patterns', 'advanced', true, null, [
            'Component architecture' => [
                ['Thinking in server and client', 'video', 15, true],
                ['Composition over configuration', 'video', 19, false],
            ],
            'State that scales' => [
                ['Server state with queries', 'video', 21, false],
                ['Patterns quiz', 'quiz', 10, false],
            ],
        ]);

        $git = $this->course($backend, $instructor, 'Git & GitHub for Teams', 'beginner', true, null, [
            'Foundations' => [
                ['Repositories and commits', 'video', 12, true],
                ['Branching strategies', 'article', 8, false],
            ],
        ]);

        /* ----------------------------- Enrollments ----------------------------- */

        $laravelLessons = $laravel->lessons()->orderBy('position')->get();
        $reactLessons = $react->lessons()->orderBy('position')->get();
        $gitLessons = $git->lessons()->orderBy('position')->get();

        $this->enroll($student, $laravel, $laravelLessons->take(5), 55.0, 60);
        $this->enroll($student, $react, $reactLessons->take(2), 33.0, 30);
        $completedEnrollment = $this->enroll($student, $git, $gitLessons, 100.0, 15);
        $completedEnrollment->update(['completed_at' => now()->subDays(10)]);

        foreach ($peers as $peer) {
            $this->enroll($peer, $laravel, $laravelLessons->take(fake()->numberBetween(0, 8)), fake()->numberBetween(5, 95), fake()->numberBetween(10, 90));
        }

        Certificate::create([
            'user_id' => $student->id,
            'course_id' => $git->id,
            'certificate_number' => 'MD-'.now()->year.'-'.Str::upper(Str::random(8)),
            'issued_at' => now()->subDays(10),
        ]);

        /* ------------------------------ Assignments ---------------------------- */

        $shipApi = Assignment::create([
            'course_id' => $laravel->id,
            'lesson_id' => $laravelLessons->firstWhere('type', 'assignment')?->id,
            'title' => 'Ship a mini REST API',
            'description' => 'Build and document a small versioned API.',
            'instructions' => '<p>Design a REST API for a todo service with authentication, validation and tests. Submit a zip or a repository link in the notes.</p>',
            'due_at' => now()->addDays(3),
            'max_score' => 100,
        ]);

        $eloquentEssay = Assignment::create([
            'course_id' => $laravel->id,
            'title' => 'Eloquent performance audit',
            'description' => 'Find and fix N+1 issues in the sample project.',
            'instructions' => '<p>Profile the provided project, list every N+1 you find, and submit your patched repository.</p>',
            'due_at' => now()->subDays(6),
            'max_score' => 50,
        ]);

        AssignmentSubmission::create([
            'assignment_id' => $eloquentEssay->id,
            'user_id' => $student->id,
            'content' => 'Found 4 N+1 spots; fixed with eager loading and a cached count. Repo: github.com/demo/audit',
            'submitted_at' => now()->subDays(7),
            'is_late' => false,
            'score' => 44,
            'feedback' => 'Great catches — remember withCount() for the dashboard widgets.',
            'graded_at' => now()->subDays(5),
            'graded_by' => $instructor->id,
        ]);

        /* -------------------------------- Quizzes ------------------------------ */

        $quiz = Quiz::create([
            'course_id' => $laravel->id,
            'lesson_id' => $laravelLessons->firstWhere('type', 'quiz')?->id,
            'title' => 'Eloquent knowledge check',
            'description' => 'Relationships, scopes and query performance.',
            'time_limit_minutes' => 15,
            'attempts_allowed' => 3,
            'passing_score' => 60,
            'is_published' => true,
        ]);

        $q1 = Question::create([
            'quiz_id' => $quiz->id,
            'type' => 'single_choice',
            'prompt' => 'Which method eager-loads a relationship?',
            'points' => 2,
            'position' => 1,
            'explanation' => 'with() loads relations up front and avoids N+1 queries.',
        ]);
        $q1correct = QuestionOption::create(['question_id' => $q1->id, 'text' => 'with()', 'is_correct' => true, 'position' => 1]);
        QuestionOption::create(['question_id' => $q1->id, 'text' => 'load()->lazy()', 'is_correct' => false, 'position' => 2]);
        QuestionOption::create(['question_id' => $q1->id, 'text' => 'find()', 'is_correct' => false, 'position' => 3]);

        $q2 = Question::create([
            'quiz_id' => $quiz->id,
            'type' => 'true_false',
            'prompt' => 'SoftDeletes removes the row from the database immediately.',
            'points' => 1,
            'position' => 2,
            'explanation' => 'Soft deleting only sets deleted_at; the row remains until force deleted.',
        ]);
        QuestionOption::create(['question_id' => $q2->id, 'text' => 'True', 'is_correct' => false, 'position' => 1]);
        $q2correct = QuestionOption::create(['question_id' => $q2->id, 'text' => 'False', 'is_correct' => true, 'position' => 2]);

        $q3 = Question::create([
            'quiz_id' => $quiz->id,
            'type' => 'short_answer',
            'prompt' => 'Name the artisan command that creates a model with a migration.',
            'points' => 2,
            'position' => 3,
        ]);

        $attempt = QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'user_id' => $student->id,
            'started_at' => now()->subDays(2)->subMinutes(12),
            'expires_at' => now()->subDays(2)->addMinutes(3),
            'submitted_at' => now()->subDays(2),
            'score' => 3,
            'max_score' => 5,
            'passed' => true,
        ]);
        QuizAnswer::create(['quiz_attempt_id' => $attempt->id, 'question_id' => $q1->id, 'selected_option_ids' => [$q1correct->id], 'is_correct' => true, 'points_awarded' => 2]);
        QuizAnswer::create(['quiz_attempt_id' => $attempt->id, 'question_id' => $q2->id, 'selected_option_ids' => [$q2correct->id], 'is_correct' => true, 'points_awarded' => 1]);
        QuizAnswer::create(['quiz_attempt_id' => $attempt->id, 'question_id' => $q3->id, 'answer_text' => 'php artisan make:migration', 'is_correct' => false, 'points_awarded' => 0]);

        $reactQuiz = Quiz::create([
            'course_id' => $react->id,
            'lesson_id' => $reactLessons->firstWhere('type', 'quiz')?->id,
            'title' => 'Patterns quiz',
            'attempts_allowed' => 2,
            'passing_score' => 70,
            'is_published' => true,
        ]);
        $rq = Question::create([
            'quiz_id' => $reactQuiz->id,
            'type' => 'single_choice',
            'prompt' => 'Where should server state live?',
            'points' => 1,
            'position' => 1,
        ]);
        QuestionOption::create(['question_id' => $rq->id, 'text' => 'In a query cache', 'is_correct' => true, 'position' => 1]);
        QuestionOption::create(['question_id' => $rq->id, 'text' => 'In useState everywhere', 'is_correct' => false, 'position' => 2]);

        /* ------------------------------ Attendance ----------------------------- */

        foreach (range(1, 15) as $daysAgo) {
            $date = now()->subDays($daysAgo);
            if ($date->isWeekend()) {
                continue;
            }
            AttendanceRecord::create([
                'user_id' => $student->id,
                'course_id' => $laravel->id,
                'session_title' => 'Live session — '.$date->format('M j'),
                'date' => $date->toDateString(),
                'status' => fake()->randomElement(['present', 'present', 'present', 'present', 'late', 'absent', 'excused']),
                'recorded_by' => $instructor->id,
            ]);
        }

        /* ---------------------------- Announcements ---------------------------- */

        $welcome = Announcement::create([
            'author_id' => $instructor->id,
            'title' => 'Welcome to the July cohort!',
            'body' => '<p>Glad to have you here. Check the calendar for this week\'s live sessions and say hello in the discussion threads.</p>',
            'is_pinned' => true,
            'published_at' => now()->subDays(2),
        ]);
        Announcement::create([
            'author_id' => $instructor->id,
            'course_id' => $laravel->id,
            'title' => 'API module drops Friday',
            'body' => '<p>The Building the API module goes live Friday — finish the Eloquent quiz before then.</p>',
            'published_at' => now()->subDay(),
        ]);
        AnnouncementRead::create(['announcement_id' => $welcome->id, 'user_id' => $student->id, 'read_at' => now()->subDay()]);

        /* ------------------------------- Calendar ------------------------------ */

        CalendarEvent::create([
            'course_id' => $laravel->id,
            'title' => 'Live Q&A: Eloquent internals',
            'type' => 'live_session',
            'starts_at' => now()->addDays(2)->setTime(17, 0),
            'ends_at' => now()->addDays(2)->setTime(18, 0),
        ]);
        CalendarEvent::create([
            'course_id' => $react->id,
            'title' => 'Office hours',
            'type' => 'live_session',
            'starts_at' => now()->addDays(5)->setTime(15, 0),
        ]);

        /* ----------------------------- Help center ----------------------------- */

        $gettingStarted = HelpCategory::create(['name' => 'Getting started', 'slug' => 'getting-started', 'position' => 1]);
        $billingHelp = HelpCategory::create(['name' => 'Billing', 'slug' => 'billing', 'position' => 2]);

        HelpArticle::create([
            'help_category_id' => $gettingStarted->id,
            'title' => 'How to enroll in a course',
            'slug' => 'how-to-enroll',
            'excerpt' => 'Find a course in the catalog and start learning in two clicks.',
            'body' => '<p>Open <strong>Courses</strong>, pick a course, and press <em>Enroll now</em>. Your progress is tracked automatically.</p>',
        ]);
        HelpArticle::create([
            'help_category_id' => $billingHelp->id,
            'title' => 'Understanding your invoices',
            'slug' => 'understanding-invoices',
            'excerpt' => 'What each invoice status means and how to pay.',
            'body' => '<p>Open invoices can be paid from the <strong>Payments</strong> page. Receipts are attached to every successful transaction.</p>',
        ]);
        Faq::create(['question' => 'Can I download lesson videos?', 'answer' => 'Streaming only — downloads are not available, but resources under each lesson are downloadable.', 'position' => 1]);
        Faq::create(['question' => 'How are certificates issued?', 'answer' => 'Finish every lesson in a course and your certificate is generated automatically within a few minutes.', 'position' => 2]);

        /* -------------------------------- Billing ------------------------------ */

        $plan = FeePlan::create([
            'user_id' => $student->id,
            'course_id' => $laravel->id,
            'title' => 'Advanced Web Development',
            'billing_cycle' => 'annual',
            'currency' => 'USD',
            'total_amount' => 1200.00,
        ]);

        $inv1 = Invoice::create([
            'fee_plan_id' => $plan->id, 'user_id' => $student->id,
            'number' => 'INV-2026-0001', 'title' => 'Tuition installment 1 of 3',
            'amount' => 400.00, 'status' => 'paid',
            'issued_at' => now()->subMonths(5), 'due_at' => now()->subMonths(4), 'paid_at' => now()->subMonths(4)->subDays(3),
        ]);
        $inv2 = Invoice::create([
            'fee_plan_id' => $plan->id, 'user_id' => $student->id,
            'number' => 'INV-2026-0002', 'title' => 'Tuition installment 2 of 3',
            'amount' => 400.00, 'status' => 'paid',
            'issued_at' => now()->subMonths(3), 'due_at' => now()->subMonths(2), 'paid_at' => now()->subMonths(2)->subDays(2),
        ]);
        Invoice::create([
            'fee_plan_id' => $plan->id, 'user_id' => $student->id,
            'number' => 'INV-2026-0003', 'title' => 'Tuition installment 3 of 3',
            'amount' => 400.00, 'status' => 'open',
            'issued_at' => now()->subDays(15), 'due_at' => now()->addMonths(2),
        ]);

        Transaction::create([
            'invoice_id' => $inv1->id, 'user_id' => $student->id, 'reference' => 'TRX-77032',
            'method_type' => 'card', 'method_brand' => 'Visa', 'method_last4' => '4242',
            'amount' => 400.00, 'status' => 'failed', 'created_at' => now()->subMonths(4)->subDays(5),
        ]);
        Transaction::create([
            'invoice_id' => $inv1->id, 'user_id' => $student->id, 'reference' => 'TRX-88154',
            'method_type' => 'bank_transfer',
            'amount' => 400.00, 'status' => 'success', 'created_at' => now()->subMonths(4)->subDays(3),
        ]);
        Transaction::create([
            'invoice_id' => $inv2->id, 'user_id' => $student->id, 'reference' => 'TRX-99201',
            'method_type' => 'card', 'method_brand' => 'Visa', 'method_last4' => '4242',
            'amount' => 400.00, 'status' => 'success', 'created_at' => now()->subMonths(2)->subDays(2),
        ]);

        /* --------------------------- Misc engagement --------------------------- */

        Bookmark::create(['user_id' => $student->id, 'bookmarkable_type' => Course::class, 'bookmarkable_id' => $react->id]);
        Bookmark::create(['user_id' => $student->id, 'bookmarkable_type' => Lesson::class, 'bookmarkable_id' => $laravelLessons->first()->id]);

        Comment::create([
            'lesson_id' => $laravelLessons->first()->id,
            'user_id' => $student->id,
            'body' => 'Loved the setup walkthrough — the docker tips saved me an hour.',
        ]);

        foreach (range(0, 27) as $daysAgo) {
            LearningActivity::create([
                'user_id' => $student->id,
                'date' => now()->subDays($daysAgo)->toDateString(),
                'minutes' => fake()->numberBetween(10, 65),
            ]);
        }
        PointEvent::create(['user_id' => $student->id, 'points' => 50, 'reason' => 'Completed Git & GitHub for Teams', 'created_at' => now()->subDays(10)]);
        PointEvent::create(['user_id' => $student->id, 'points' => 20, 'reason' => 'Passed Eloquent knowledge check', 'created_at' => now()->subDays(2)]);

        /* ---------------------------- Biometric device ------------------------- */

        $device = \App\Models\BiometricDevice::create([
            'name' => 'Main Lab Terminal',
            'vendor' => 'ZKTeco',
            'serial_number' => 'ZK-4590-DEMO',
            'location' => 'Lab 2, main campus',
            'course_id' => $laravel->id,
            'api_key' => \App\Models\BiometricDevice::generateKey(),
            'session_start' => '17:00',
            'late_after_minutes' => 15,
            'last_seen_at' => now()->subHours(2),
        ]);

        $biometric = app(\App\Services\BiometricAttendanceService::class);
        $biometric->ingest($device, [
            'biometric_id' => '1001',
            'punched_at' => now()->subDay()->setTime(17, 5)->toDateTimeString(),
            'direction' => 'in',
        ]);
        $biometric->ingest($device, [
            'biometric_id' => '9999',
            'punched_at' => now()->subDay()->setTime(17, 20)->toDateTimeString(),
            'direction' => 'in',
        ]);

        /* ---------------------------- Notifications ---------------------------- */

        $this->notify($student, 'assignment_graded', 'Assignment graded', 'Your Eloquent performance audit scored 44/50.', '/assignments/'.$eloquentEssay->id);
        $this->notify($student, 'announcement_posted', 'New announcement', 'API module drops Friday.', '/announcements');
        $this->notify($student, 'due_reminder', 'Due soon', 'Ship a mini REST API is due in 3 days.', '/assignments/'.$shipApi->id, read: false);
    }

    /** @param array<string, array<int, array{0: string, 1: string, 2: int, 3: bool}>> $curriculum */
    protected function course(Category $category, User $instructor, string $title, string $level, bool $free, ?float $price, array $curriculum): Course
    {
        $course = Course::create([
            'category_id' => $category->id,
            'instructor_id' => $instructor->id,
            'title' => $title,
            'slug' => Str::slug($title),
            'excerpt' => fake()->sentence(12),
            'description' => '<p>'.fake()->paragraph(4).'</p><h3>What you\'ll learn</h3><ul><li>'.implode('</li><li>', fake()->sentences(4)).'</li></ul>',
            'level' => $level,
            'is_free' => $free,
            'price' => $price,
            'status' => 'published',
            'rating' => fake()->randomFloat(1, 4.2, 4.9),
            'tags' => ['markdev', $category->slug],
            'published_at' => now()->subMonths(2),
        ]);

        $modulePosition = 0;
        $lessonPosition = 0;
        $totalMinutes = 0;

        foreach ($curriculum as $moduleTitle => $lessons) {
            $module = Module::create([
                'course_id' => $course->id,
                'title' => $moduleTitle,
                'position' => ++$modulePosition,
            ]);

            foreach ($lessons as [$lessonTitle, $type, $minutes, $preview]) {
                $lesson = Lesson::create([
                    'module_id' => $module->id,
                    'course_id' => $course->id,
                    'title' => $lessonTitle,
                    'type' => $type,
                    'content' => $type === 'article' ? '<p>'.fake()->paragraph(6).'</p><pre><code>php artisan serve</code></pre><p>'.fake()->paragraph(3).'</p>' : null,
                    'duration_minutes' => $minutes,
                    'position' => ++$lessonPosition,
                    'is_preview' => $preview,
                ]);
                $totalMinutes += $minutes;

                if ($type === 'video') {
                    Video::create([
                        'lesson_id' => $lesson->id,
                        'provider' => 'youtube',
                        'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                        'embed_url' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
                        'duration_seconds' => $minutes * 60,
                    ]);
                }
            }
        }

        $course->update(['duration_minutes' => $totalMinutes]);

        return $course;
    }

    protected function enroll(User $user, Course $course, $completedLessons, float $percent, int $daysAgo): Enrollment
    {
        $enrollment = Enrollment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'enrolled_at' => now()->subDays($daysAgo),
            'progress_percent' => $percent,
            'last_activity_at' => now()->subDays(fake()->numberBetween(0, 3)),
        ]);

        foreach ($completedLessons as $lesson) {
            LessonCompletion::create([
                'user_id' => $user->id,
                'lesson_id' => $lesson->id,
                'course_id' => $course->id,
                'completed_at' => now()->subDays(fake()->numberBetween(1, $daysAgo)),
            ]);
        }

        return $enrollment;
    }

    protected function notify(User $user, string $type, string $title, string $message, ?string $actionUrl, bool $read = true): void
    {
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\'.Str::studly($type),
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => $title, 'message' => $message, 'action_url' => $actionUrl]),
            'read_at' => $read ? now()->subDay() : null,
            'created_at' => now()->subDays(fake()->numberBetween(0, 2)),
            'updated_at' => now(),
        ]);
    }
}
