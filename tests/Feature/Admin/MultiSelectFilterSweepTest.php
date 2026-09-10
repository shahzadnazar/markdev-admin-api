<?php

namespace Tests\Feature\Admin;

use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Note;
use App\Models\Quiz;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One per screen for the rest of the multi-select conversion.
 *
 * The three questions that matter on each are the same, so each screen gets
 * the sharpest one: two values ticked shows both and nothing else. The shared
 * concern's edges — an old single-value URL, an empty selection, an unknown
 * value — are covered once in MultiSelectFilterTest, since there is one
 * implementation of them.
 */
class MultiSelectFilterSweepTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $this->category = Category::create(['name' => 'Web', 'slug' => 'web-'.Str::random(4)]);
    }

    protected function course(string $title): Course
    {
        return Course::create([
            'title' => $title,
            'slug' => Str::slug($title.'-'.Str::random(4)),
            'excerpt' => 'x',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'is_free' => true,
            'category_id' => $this->category->id,
        ]);
    }

    /** @return array{0: Course, 1: Course, 2: Course} */
    protected function threeCourses(): array
    {
        return [$this->course('Alpha course'), $this->course('Beta course'), $this->course('Gamma course')];
    }

    /* ------------------------------ Enrollments ------------------------------ */

    public function test_enrollments_filters_by_several_courses(): void
    {
        [$a, $b, $c] = $this->threeCourses();

        foreach ([[$a, 'Ann Alpha'], [$b, 'Ben Beta'], [$c, 'Cal Gamma']] as [$course, $name]) {
            $student = User::factory()->create(['name' => $name]);
            $student->assignRole('student');
            Enrollment::create(['user_id' => $student->id, 'course_id' => $course->id, 'enrolled_at' => now()->subMonth()]);
        }

        $this->actingAs($this->admin)
            ->get(route('admin.enrollments.index', ['course' => [$a->id, $b->id]]))->assertOk()
            ->assertSee('Ann Alpha', false)
            ->assertSee('Ben Beta', false)
            ->assertDontSee('Cal Gamma', false);
    }

    /* ----------------------------- Announcements ----------------------------- */

    public function test_announcements_filters_by_several_courses(): void
    {
        [$a, $b, $c] = $this->threeCourses();

        foreach ([[$a, 'Alpha notice'], [$b, 'Beta notice'], [$c, 'Gamma notice']] as [$course, $title]) {
            Announcement::create([
                'title' => $title, 'body' => 'x', 'course_id' => $course->id,
                'author_id' => $this->admin->id, 'published_at' => now()->subDay(),
            ]);
        }

        $this->actingAs($this->admin)
            ->get(route('admin.announcements.index', ['course' => [$a->id, $b->id]]))->assertOk()
            ->assertSee('Alpha notice', false)
            ->assertSee('Beta notice', false)
            ->assertDontSee('Gamma notice', false);
    }

    /* ------------------------------ Assignments ------------------------------ */

    public function test_assignments_filters_by_several_courses(): void
    {
        [$a, $b, $c] = $this->threeCourses();

        foreach ([[$a, 'Alpha task'], [$b, 'Beta task'], [$c, 'Gamma task']] as [$course, $title]) {
            Assignment::create([
                'course_id' => $course->id, 'title' => $title,
                'due_at' => now()->addWeek(), 'max_score' => 10,
            ]);
        }

        $this->actingAs($this->admin)
            ->get(route('admin.assignments.index', ['course' => [$a->id, $b->id]]))->assertOk()
            ->assertSee('Alpha task', false)
            ->assertSee('Beta task', false)
            ->assertDontSee('Gamma task', false);
    }

    /* --------------------------------- Notes --------------------------------- */

    public function test_notes_filters_by_several_courses(): void
    {
        [$a, $b, $c] = $this->threeCourses();

        foreach ([[$a, 'Alpha note'], [$b, 'Beta note'], [$c, 'Gamma note']] as [$course, $title]) {
            Note::create([
                'course_id' => $course->id, 'instructor_id' => $this->admin->id,
                'title' => $title, 'file_path' => 'notes/x.pdf', 'size_bytes' => 1000,
            ]);
        }

        $this->actingAs($this->admin)
            ->get(route('admin.notes.index', ['course' => [$a->id, $b->id]]))->assertOk()
            ->assertSee('Alpha note', false)
            ->assertSee('Beta note', false)
            ->assertDontSee('Gamma note', false);
    }

    /* -------------------------------- Quizzes -------------------------------- */

    public function test_quizzes_filters_by_several_courses(): void
    {
        [$a, $b, $c] = $this->threeCourses();

        foreach ([[$a, 'Alpha quiz'], [$b, 'Beta quiz'], [$c, 'Gamma quiz']] as [$course, $title]) {
            Quiz::create([
                'course_id' => $course->id, 'title' => $title,
                'attempts_allowed' => 1, 'pass_mark' => 50, 'is_published' => true,
            ]);
        }

        $this->actingAs($this->admin)
            ->get(route('admin.quizzes.index', ['course' => [$a->id, $b->id]]))->assertOk()
            ->assertSee('Alpha quiz', false)
            ->assertSee('Beta quiz', false)
            ->assertDontSee('Gamma quiz', false);
    }

    /* ------------------------------- Audit logs ------------------------------ */

    /**
     * Every model in this app is Auditable, so the fixtures above have already
     * written a trail of their own. Cleared first, or these assert against
     * whatever the setup happened to log.
     */
    protected function onlyTheseLogs(): void
    {
        AuditLog::query()->delete();
    }

    protected function logEntry(string $action, string $module, ?User $user = null): AuditLog
    {
        return AuditLog::create([
            'user_id' => $user?->id ?? $this->admin->id,
            'user_name' => $user?->name ?? $this->admin->name,
            'action' => $action,
            'module' => $module,
            'url' => '/admin/'.$module,
        ]);
    }

    public function test_audit_logs_filters_by_several_actions(): void
    {
        $this->onlyTheseLogs();

        $this->logEntry('created', 'courses');
        $this->logEntry('updated', 'courses');
        $this->logEntry('deleted', 'courses');

        // partial=1 returns the rows alone. The whole page would also carry
        // the Action dropdown, which lists every value the log holds — so
        // asserting "deleted" is absent there would fail on the option, not
        // on a row.
        $this->actingAs($this->admin)
            ->get(route('admin.audit-logs.index', ['action' => ['created', 'updated'], 'partial' => 1]))->assertOk()
            ->assertSee('created', false)
            ->assertSee('updated', false)
            ->assertDontSee('deleted', false);
    }

    public function test_audit_logs_filters_by_several_modules(): void
    {
        $this->onlyTheseLogs();

        $this->logEntry('created', 'courses');
        $this->logEntry('created', 'invoices');
        $this->logEntry('created', 'notes');

        $this->actingAs($this->admin)
            ->get(route('admin.audit-logs.index', ['module' => ['courses', 'invoices'], 'partial' => 1]))->assertOk()
            ->assertSee('/admin/courses', false)
            ->assertSee('/admin/invoices', false)
            ->assertDontSee('/admin/notes', false);
    }

    public function test_audit_logs_filters_by_several_users(): void
    {
        $this->onlyTheseLogs();

        $one = User::factory()->create(['name' => 'Logger One']);
        $two = User::factory()->create(['name' => 'Logger Two']);
        $three = User::factory()->create(['name' => 'Logger Three']);
        $this->logEntry('created', 'courses', $one);
        $this->logEntry('created', 'courses', $two);
        $this->logEntry('created', 'courses', $three);

        $this->actingAs($this->admin)
            ->get(route('admin.audit-logs.index', ['user' => [$one->id, $two->id], 'partial' => 1]))->assertOk()
            ->assertSee('Logger One', false)
            ->assertSee('Logger Two', false)
            ->assertDontSee('Logger Three', false);
    }

    /**
     * The CSV is taken from the screen, so it has to honour the same ticks.
     * Its docblock says the export exists to match what the admin is looking
     * at, and a filter that only half-crossed would quietly break that.
     */
    public function test_the_audit_csv_honours_a_multi_value_filter(): void
    {
        $this->onlyTheseLogs();

        $this->logEntry('created', 'courses');
        $this->logEntry('updated', 'courses');
        $this->logEntry('deleted', 'courses');

        $rows = \App\Exports\AuditLogsExport::filteredQuery([
            'action' => ['created', 'updated'],
        ])->get();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['created', 'updated'], $rows->pluck('action')->all());
    }

    public function test_the_audit_csv_still_takes_an_old_single_value(): void
    {
        $this->onlyTheseLogs();

        $this->logEntry('created', 'courses');
        $this->logEntry('updated', 'courses');

        $rows = \App\Exports\AuditLogsExport::filteredQuery(['action' => 'created'])->get();

        $this->assertCount(1, $rows);
        $this->assertSame('created', $rows->first()->action);
    }

    public function test_an_empty_audit_filter_is_no_filter(): void
    {
        $this->onlyTheseLogs();

        $this->logEntry('created', 'courses');
        $this->logEntry('updated', 'courses');

        $this->assertCount(2, \App\Exports\AuditLogsExport::filteredQuery(['action' => []])->get());
    }

    /* --------------------- What stays single, and why ------------------------ */

    /**
     * The daily register keeps single-select on both of its filters.
     *
     * `unmarked` is not a status but the absence of one, so it cannot be OR'd
     * with real statuses without changing what the screen means; and `course`
     * there picks the cohort the day-summary counts and the bulk actions are
     * computed for, which are defined one cohort at a time.
     */
    public function test_the_daily_register_filters_are_deliberately_single(): void
    {
        $view = file_get_contents(resource_path('views/admin/attendance/daily.blade.php'));

        $this->assertStringContainsString('<select name="status"', $view);
        $this->assertStringContainsString('<select name="course"', $view);
        $this->assertStringContainsString('value="unmarked"', $view);
    }

    public function test_holidays_keeps_a_single_year(): void
    {
        // A period, not a set: 2025 and 2027 but not 2026 is not a question.
        $view = file_get_contents(resource_path('views/admin/holidays/index.blade.php'));

        $this->assertStringContainsString('name="year"', $view);
        $this->assertStringNotContainsString('name="year[]"', $view);
    }
}
