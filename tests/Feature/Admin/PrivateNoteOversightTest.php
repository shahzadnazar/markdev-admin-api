<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonPrivateNote;
use App\Models\Module;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A super-admin may read a student's private notes. Nobody else may.
 *
 * The table had no admin path at all by design, so every role is tested on its
 * own rather than as "not a super-admin" — an admin inheriting the power by
 * accident is exactly the failure worth catching, and admin holds nearly every
 * permission in the matrix.
 *
 * The gate is a ROLE check rather than a permission precisely because a
 * permission is grantable: a checkbox on the Roles page reading "private notes"
 * would let a super-admin hand this to an admin, which is the promise the
 * portal now makes to students and would then have to un-make.
 */
class PrivateNoteOversightTest extends TestCase
{
    use RefreshDatabase;

    protected LessonPrivateNote $note;

    protected User $author;

    protected Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $course = Course::create([
            'title' => 'Laravel', 'slug' => 'laravel-'.Str::random(6), 'excerpt' => 'x',
            'level' => 'beginner', 'status' => 'published', 'published_at' => now()->subDay(),
            'is_free' => true,
            'category_id' => Category::create(['name' => 'Web', 'slug' => 'web-'.Str::random(4)])->id,
        ]);
        $module = Module::create(['course_id' => $course->id, 'title' => 'M1', 'position' => 1]);
        $this->lesson = Lesson::create([
            'module_id' => $module->id, 'course_id' => $course->id,
            'title' => 'Eloquent basics', 'type' => 'video', 'position' => 1,
        ]);

        $this->author = User::factory()->create(['name' => 'Ali Raza']);
        $this->author->assignRole('student');

        $this->note = LessonPrivateNote::create([
            'lesson_id' => $this->lesson->id,
            'user_id' => $this->author->id,
            'body' => 'PRIVATE-NOTE-BODY',
        ]);
    }

    protected function userWith(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    // ------------------------------------------------- who may not, one by one

    public function test_an_admin_cannot_read_a_private_note(): void
    {
        $admin = $this->userWith('admin');

        $this->actingAs($admin)->get(route('admin.private-notes.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.private-notes.show', $this->note))->assertForbidden();
    }

    public function test_a_manager_cannot_read_a_private_note(): void
    {
        $manager = $this->userWith('manager');

        $this->actingAs($manager)->get(route('admin.private-notes.index'))->assertForbidden();
        $this->actingAs($manager)->get(route('admin.private-notes.show', $this->note))->assertForbidden();
    }

    public function test_an_instructor_cannot_read_a_private_note(): void
    {
        // Including the instructor who teaches the very lesson.
        $instructor = $this->userWith('instructor');
        $this->lesson->course->update(['instructor_id' => $instructor->id]);

        $this->actingAs($instructor)->get(route('admin.private-notes.index'))->assertForbidden();
        $this->actingAs($instructor)->get(route('admin.private-notes.show', $this->note))->assertForbidden();
    }

    public function test_another_student_cannot_read_a_private_note(): void
    {
        $classmate = $this->userWith('student');

        $this->actingAs($classmate)->get(route('admin.private-notes.show', $this->note))->assertForbidden();
    }

    public function test_nobody_but_a_super_admin_is_offered_the_sidebar_link(): void
    {
        foreach (['admin', 'manager', 'instructor'] as $role) {
            $page = $this->actingAs($this->userWith($role))
                ->get(route('admin.dashboard'))
                ->assertOk()
                ->getContent();

            $this->assertStringNotContainsString(
                route('admin.private-notes.index'),
                $page,
                "{$role} should not see the private notes link",
            );
        }
    }

    // --------------------------------------------------------- who may, audited

    public function test_a_super_admin_can_read_it_and_the_read_is_audited(): void
    {
        $superAdmin = $this->userWith('super-admin');

        $this->actingAs($superAdmin)
            ->get(route('admin.private-notes.show', $this->note))
            ->assertOk()
            ->assertSee('PRIVATE-NOTE-BODY');

        $row = AuditLog::where('module', 'private_notes')->where('action', 'viewed')->first();

        $this->assertNotNull($row, 'opening a note must leave a trace');
        $this->assertSame($superAdmin->id, $row->user_id, 'names the reader');
        $this->assertSame($this->note->id, $row->record_id);
        $this->assertSame($this->author->id, $row->new_values['student_id'], 'names the student');
        $this->assertSame('Ali Raza', $row->new_values['student_name']);
        $this->assertSame($this->lesson->id, $row->new_values['lesson_id'], 'names the lesson');
        $this->assertSame('Eloquent basics', $row->new_values['lesson_title']);

        // The note's text is not copied into the trail: the row records that
        // it was opened, and duplicating the body would spread the very thing
        // being protected.
        $this->assertStringNotContainsString('PRIVATE-NOTE-BODY', json_encode($row->new_values));
    }

    public function test_each_opening_is_its_own_row(): void
    {
        // One request, one note, one row. An index that returned bodies would
        // have made a hundred reads look like one.
        $superAdmin = $this->userWith('super-admin');

        $this->actingAs($superAdmin)->get(route('admin.private-notes.show', $this->note))->assertOk();
        $this->actingAs($superAdmin)->get(route('admin.private-notes.show', $this->note))->assertOk();

        $this->assertSame(2, AuditLog::where('module', 'private_notes')->where('action', 'viewed')->count());
    }

    public function test_the_index_shows_that_a_note_exists_and_never_what_it_says(): void
    {
        $superAdmin = $this->userWith('super-admin');

        $page = $this->actingAs($superAdmin)
            ->get(route('admin.private-notes.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Ali Raza', $page);
        $this->assertStringContainsString('Eloquent basics', $page);
        $this->assertStringNotContainsString('PRIVATE-NOTE-BODY', $page);

        // Nothing was read, so nothing is logged.
        $this->assertSame(0, AuditLog::where('module', 'private_notes')->count());
    }

    public function test_the_index_does_not_even_load_the_bodies(): void
    {
        /*
         * Reaches past the markup. The rendered page proves only that the
         * template does not print the body — with the column still selected it
         * looks identical, and the body would be sitting in memory, in the
         * query log, and one `@dump` or one added column away from the screen.
         * The audit story depends on content being read in exactly one place,
         * so not loading it here is the property that matters.
         */
        $this->actingAs($this->userWith('super-admin'))
            ->get(route('admin.private-notes.index'))
            ->assertOk()
            ->assertViewHas('notes', function ($notes) {
                return $notes->every(fn ($note) => ! array_key_exists('body', $note->getAttributes()));
            });
    }

    public function test_the_audit_log_shows_karachi_time_in_twelve_hour(): void
    {
        // The rows this feature writes are read on that screen, and it was
        // still on 24-hour H:i:s in both the list and the detail panel while
        // the rest of the admin uses g:i A.
        $superAdmin = $this->userWith('super-admin');
        $this->actingAs($superAdmin)->get(route('admin.private-notes.show', $this->note))->assertOk();

        $page = $this->actingAs($superAdmin)
            ->get(route('admin.audit-logs.index', ['module' => ['private_notes']]))
            ->assertOk()
            ->getContent();

        $this->assertSame('Asia/Karachi', config('app.timezone'));
        $this->assertMatchesRegularExpression('/\d{1,2}:\d{2}:\d{2} (AM|PM)/', $page);
        $this->assertDoesNotMatchRegularExpression('/\d{1,2} \w{3} · \d{2}:\d{2}:\d{2}</', $page);
    }

    // ------------------------------------------------------------- read only

    public function test_there_is_no_route_that_can_change_a_note(): void
    {
        /*
         * Read-only is an absence of verbs, not a policy someone could later
         * widen. This walks the route table rather than trying a URL, because
         * the assertion is that no such endpoint exists anywhere — including
         * one added next month.
         */
        $writeable = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'private-note'))
            ->reject(fn ($route) => $route->methods() === ['GET', 'HEAD'])
            // The student's own PUT is theirs, and is not oversight.
            ->reject(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $writeable, 'the oversight path must be GET only');
    }

    public function test_a_super_admin_cannot_edit_or_delete_a_note_through_the_student_api(): void
    {
        // The other door. The student endpoints scope by user_id, so a
        // super-admin writing there edits their OWN note, never the student's.
        $superAdmin = $this->userWith('super-admin');
        \App\Models\Enrollment::create([
            'user_id' => $superAdmin->id,
            'course_id' => $this->lesson->course_id,
            'enrolled_at' => now(),
            'progress_percent' => 0,
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($superAdmin);

        $this->putJson("/api/v1/lessons/{$this->lesson->id}/private-note", ['body' => 'OVERWRITTEN'])
            ->assertOk();

        $this->assertSame('PRIVATE-NOTE-BODY', $this->note->fresh()->body, "the student's note is untouched");
        $this->assertSame(2, LessonPrivateNote::count(), 'a second note was created, not the first edited');
    }
}
