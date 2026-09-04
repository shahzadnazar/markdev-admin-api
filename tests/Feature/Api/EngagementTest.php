<?php

namespace Tests\Feature\Api;

use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\AttendanceRecord;
use App\Models\CalendarEvent;
use App\Models\Certificate;
use App\Models\Faq;
use App\Models\HelpArticle;
use App\Models\HelpCategory;
use App\Models\LearningActivity;
use App\Models\LessonCompletion;
use App\Models\User;

class EngagementTest extends ApiTestCase
{
    public function test_dashboard_aggregates_the_student_world(): void
    {
        $user = $this->actingAsStudent();
        [$course, , $lessons] = $this->makeCourse(2);
        $this->enroll($user, $course, ['progress_percent' => 50, 'last_activity_at' => now()]);

        LessonCompletion::create([
            'user_id' => $user->id, 'lesson_id' => $lessons[0]->id,
            'course_id' => $course->id, 'completed_at' => now(),
        ]);
        LearningActivity::create(['user_id' => $user->id, 'date' => now()->toDateString(), 'minutes' => 90]);
        LearningActivity::create(['user_id' => $user->id, 'date' => now()->subDay()->toDateString(), 'minutes' => 30]);

        Assignment::create(['course_id' => $course->id, 'title' => 'Pending work', 'due_at' => now()->addDay(), 'max_score' => 10]);
        AttendanceRecord::create(['user_id' => $user->id, 'course_id' => $course->id, 'date' => now()->toDateString(), 'status' => 'present']);
        AttendanceRecord::create(['user_id' => $user->id, 'course_id' => $course->id, 'date' => now()->subDay()->toDateString(), 'status' => 'absent']);

        $response = $this->getJson('/api/v1/dashboard')->assertOk();

        $response->assertJsonPath('data.stats.enrolled_courses', 1)
            ->assertJsonPath('data.stats.completed_courses', 0)
            ->assertJsonPath('data.stats.completed_lessons', 1)
            ->assertJsonPath('data.stats.hours_learned', 2)
            ->assertJsonPath('data.stats.pending_assignments', 1)
            ->assertJsonPath('data.stats.current_streak_days', 2)
            ->assertJsonPath('data.stats.attendance_rate', 50)
            ->assertJsonPath('data.continue_learning.0.course.id', $course->id)
            ->assertJsonPath('data.continue_learning.0.completed_lessons', 1)
            ->assertJsonPath('data.continue_learning.0.total_lessons', 2)
            ->assertJsonPath('data.continue_learning.0.time_spent_minutes', 10);

        $this->assertCount(28, $response->json('data.activity'));
        $this->assertSame(90, collect($response->json('data.activity'))->last()['minutes']);
    }

    public function test_attendance_list_and_summary(): void
    {
        $user = $this->actingAsStudent();
        [$course] = $this->makeCourse(1);

        foreach ([['present', 0], ['present', 1], ['late', 2], ['absent', 3]] as [$status, $daysAgo]) {
            AttendanceRecord::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'date' => now()->subDays($daysAgo)->toDateString(),
                'status' => $status,
                'session_title' => 'Session',
            ]);
        }

        // Another student's records never leak.
        AttendanceRecord::create([
            'user_id' => $this->student()->id, 'course_id' => $course->id,
            'date' => now()->toDateString(), 'status' => 'present',
        ]);

        $this->getJson('/api/v1/attendance')->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.status', 'present')
            ->assertJsonPath('data.0.course.id', $course->id);

        $this->getJson('/api/v1/attendance?status=late')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/attendance?from='.now()->subDay()->toDateString())
            ->assertOk()->assertJsonCount(2, 'data');

    }

    public function test_daily_register_summary_counts_leave_and_filters(): void
    {
        $user = $this->actingAsStudent();

        foreach ([['present', 0], ['present', 1], ['late', 2], ['absent', 3], ['leave', 4]] as [$status, $daysAgo]) {
            \App\Models\DailyAttendance::create([
                'user_id' => $user->id,
                'date' => now()->subDays($daysAgo)->toDateString(),
                'status' => $status,
                'source' => 'manual',
                'marked_at' => now(),
            ]);
        }

        // A day nobody has marked yet is not a status and never reaches a count.
        \App\Models\DailyAttendance::create([
            'user_id' => $user->id,
            'date' => now()->subDays(5)->toDateString(),
            'status' => \App\Models\DailyAttendance::PENDING,
            'source' => 'manual',
            'marked_at' => now(),
        ]);

        // Another student's register never leaks.
        \App\Models\DailyAttendance::create([
            'user_id' => $this->student()->id,
            'date' => now()->toDateString(),
            'status' => 'absent',
            'source' => 'manual',
            'marked_at' => now(),
        ]);

        // Approved leave is a day like any other here — the card used to read
        // zero because this counted per-class records, which leave never
        // touches.
        $this->getJson('/api/v1/attendance/summary')->assertOk()->assertJson([
            'data' => [
                'total_sessions' => 5,
                'present_count' => 2,
                'absent_count' => 1,
                'late_count' => 1,
                'leave_count' => 1,
                // (100 + 100 + 70 + 0 + 50) / 5
                'attendance_rate' => 64.0,
            ],
        ]);

        $this->getJson('/api/v1/attendance/daily')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson('/api/v1/attendance/daily?status=leave')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'leave');

        // The upper bound includes its own day, which a plain `<=` against a
        // date-cast column does not on every engine.
        $today = now()->toDateString();
        $this->getJson("/api/v1/attendance/daily?from={$today}&to={$today}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.date', $today);

        $this->getJson('/api/v1/attendance/summary?from='.now()->subDays(2)->toDateString())
            ->assertOk()->assertJsonPath('data.total_sessions', 3)
            ->assertJsonPath('data.leave_count', 0);
    }

    public function test_certificates_list_and_signed_download(): void
    {
        $user = $this->actingAsStudent();
        [$course] = $this->makeCourse(1);

        Certificate::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'certificate_number' => 'MD-2026-ABCD1234',
            'issued_at' => now()->subDay(),
        ]);

        $response = $this->getJson('/api/v1/certificates')->assertOk();
        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.certificate_number', 'MD-2026-ABCD1234')
            ->assertJsonPath('data.0.course.id', $course->id)
            ->assertJsonPath('data.0.preview_url', null);

        $downloadUrl = $response->json('data.0.download_url');
        $this->assertStringContainsString('signature=', $downloadUrl);

        $this->get($downloadUrl)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // Without a valid signature the download is rejected.
        $this->get(explode('?', $downloadUrl)[0])->assertForbidden();
    }

    public function test_progress_overview(): void
    {
        $user = $this->actingAsStudent();
        [$course, , $lessons] = $this->makeCourse(2);
        [$done] = $this->makeCourse(1);
        $this->enroll($user, $course, ['progress_percent' => 50]);
        $this->enroll($user, $done, ['progress_percent' => 100, 'completed_at' => now()]);

        LessonCompletion::create([
            'user_id' => $user->id, 'lesson_id' => $lessons[0]->id,
            'course_id' => $course->id, 'completed_at' => now(),
        ]);
        LearningActivity::create(['user_id' => $user->id, 'date' => now()->toDateString(), 'minutes' => 45]);

        $response = $this->getJson('/api/v1/progress')->assertOk();

        $response->assertJsonPath('data.enrolled_courses', 2)
            ->assertJsonPath('data.completed_courses', 1)
            ->assertJsonPath('data.completed_lessons', 1)
            ->assertJsonPath('data.total_time_minutes', 45)
            ->assertJsonPath('data.current_streak_days', 1)
            ->assertJsonPath('data.longest_streak_days', 1);

        $this->assertCount(84, $response->json('data.activity'));
        $this->assertCount(2, $response->json('data.courses'));
    }

    public function test_leaderboard_ranks_students_and_always_includes_me(): void
    {
        $me = $this->actingAsStudent(
            $this->student(['points' => 5]),
        );

        foreach (range(1, 11) as $i) {
            $this->student(['points' => 100 + $i]);
        }

        $response = $this->getJson('/api/v1/leaderboard?period=all_time')->assertOk();

        $response->assertJsonPath('data.period', 'all_time')
            ->assertJsonCount(10, 'data.entries')
            ->assertJsonPath('data.entries.0.rank', 1)
            ->assertJsonPath('data.entries.0.points', 111)
            ->assertJsonPath('data.me.is_me', true)
            ->assertJsonPath('data.me.rank', 12)
            ->assertJsonPath('data.me.points', 5);

        $this->assertFalse(collect($response->json('data.entries'))->contains('is_me', true));

        $this->getJson('/api/v1/leaderboard?period=hourly')->assertStatus(422);
    }

    public function test_announcements_visibility_ordering_and_read_state(): void
    {
        $user = $this->actingAsStudent();
        $author = User::factory()->create();
        [$enrolledCourse] = $this->makeCourse(1);
        [$otherCourse] = $this->makeCourse(1);
        $this->enroll($user, $enrolledCourse);

        $global = Announcement::create([
            'author_id' => $author->id, 'title' => 'Global news', 'body' => '<p>Hi</p>',
            'published_at' => now()->subDays(2),
        ]);
        $pinned = Announcement::create([
            'author_id' => $author->id, 'course_id' => $enrolledCourse->id, 'title' => 'Pinned',
            'body' => '<p>Pin</p>', 'is_pinned' => true, 'published_at' => now()->subDays(5),
        ]);
        $hidden = Announcement::create([
            'author_id' => $author->id, 'course_id' => $otherCourse->id, 'title' => 'Not for you',
            'body' => '<p>No</p>', 'published_at' => now()->subDay(),
        ]);
        Announcement::create([
            'author_id' => $author->id, 'title' => 'Unpublished', 'body' => '<p>Draft</p>',
        ]);

        $this->getJson('/api/v1/announcements')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $pinned->id) // pinned first
            ->assertJsonPath('data.0.is_read', false)
            ->assertJsonPath('data.1.id', $global->id);

        $this->postJson("/api/v1/announcements/{$global->id}/read")->assertOk();
        $this->getJson("/api/v1/announcements/{$global->id}")->assertOk()
            ->assertJsonPath('data.is_read', true);

        $this->getJson("/api/v1/announcements/{$hidden->id}")->assertForbidden();
    }

    public function test_calendar_merges_assignments_quizzes_and_events(): void
    {
        $user = $this->actingAsStudent();
        [$course] = $this->makeCourse(1);
        $this->enroll($user, $course);

        $assignment = Assignment::create([
            'course_id' => $course->id, 'title' => 'Due soon',
            'due_at' => now()->addDays(2), 'max_score' => 10,
        ]);
        \App\Models\Quiz::create([
            'course_id' => $course->id, 'title' => 'Closing quiz',
            'attempts_allowed' => 1, 'passing_score' => 60, 'is_published' => true,
            'available_until' => now()->addDays(4),
        ]);
        CalendarEvent::create([
            'course_id' => null, 'title' => 'Town hall', 'type' => 'live_session',
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(),
            'action_url' => '/calendar',
        ]);
        // Outside the range: excluded.
        Assignment::create([
            'course_id' => $course->id, 'title' => 'Far future',
            'due_at' => now()->addDays(60), 'max_score' => 10,
        ]);

        $response = $this->getJson('/api/v1/calendar?from='.now()->toDateString().'&to='.now()->addDays(7)->toDateString())
            ->assertOk();

        $events = collect($response->json('data'));
        $this->assertCount(3, $events);
        // Sorted by starts_at: live session, assignment, quiz.
        $this->assertSame(['live_session', 'assignment', 'quiz'], $events->pluck('type')->all());
        $this->assertSame("/assignments/{$assignment->id}", $events->firstWhere('type', 'assignment')['action_url']);
        $this->assertSame($course->id, $events->firstWhere('type', 'quiz')['course']['id']);
    }

    public function test_search_returns_grouped_results(): void
    {
        $user = $this->actingAsStudent();
        [$course, , $lessons] = $this->makeCourse(1, ['title' => 'Laravel Mastery', 'slug' => 'laravel-mastery'], ['title' => 'Laravel setup']);
        $this->enroll($user, $course);

        Assignment::create(['course_id' => $course->id, 'title' => 'Laravel API homework', 'max_score' => 10]);
        \App\Models\Quiz::create([
            'course_id' => $course->id, 'title' => 'Laravel quiz',
            'attempts_allowed' => 1, 'passing_score' => 60, 'is_published' => true,
        ]);
        Announcement::create([
            'author_id' => User::factory()->create()->id, 'title' => 'Laravel week',
            'body' => '<p>Yes</p>', 'published_at' => now()->subDay(),
        ]);

        $this->getJson('/api/v1/search?q=laravel')->assertOk()
            ->assertJsonPath('data.courses.0.slug', 'laravel-mastery')
            ->assertJsonPath('data.lessons.0.course_title', 'Laravel Mastery')
            ->assertJsonPath('data.assignments.0.title', 'Laravel API homework')
            ->assertJsonPath('data.quizzes.0.title', 'Laravel quiz')
            ->assertJsonPath('data.announcements.0.title', 'Laravel week');

        $this->getJson('/api/v1/search?q=a')->assertStatus(422);
    }

    public function test_help_center_endpoints(): void
    {
        $this->actingAsStudent();

        $category = HelpCategory::create(['name' => 'Getting started', 'slug' => 'getting-started', 'position' => 1]);
        HelpArticle::create([
            'help_category_id' => $category->id, 'title' => 'How to enroll', 'slug' => 'how-to-enroll',
            'excerpt' => 'Two clicks.', 'body' => '<p>Full body</p>', 'is_published' => true,
        ]);
        HelpArticle::create([
            'help_category_id' => $category->id, 'title' => 'Hidden draft', 'slug' => 'hidden-draft',
            'is_published' => false,
        ]);
        Faq::create(['question' => 'Q?', 'answer' => 'A.', 'position' => 1, 'is_published' => true]);
        Faq::create(['question' => 'Secret?', 'answer' => 'No.', 'position' => 2, 'is_published' => false]);

        $this->getJson('/api/v1/help/categories')->assertOk()
            ->assertJsonPath('data.0.slug', 'getting-started')
            ->assertJsonPath('data.0.articles_count', 1);

        $this->getJson('/api/v1/help/articles?category=getting-started')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'how-to-enroll')
            ->assertJsonPath('data.0.body', null);

        $this->getJson('/api/v1/help/articles/how-to-enroll')->assertOk()
            ->assertJsonPath('data.body', '<p>Full body</p>')
            ->assertJsonPath('data.category.slug', 'getting-started');

        $this->getJson('/api/v1/help/articles/hidden-draft')->assertNotFound();

        $this->getJson('/api/v1/help/faqs')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.question', 'Q?');
    }
}
