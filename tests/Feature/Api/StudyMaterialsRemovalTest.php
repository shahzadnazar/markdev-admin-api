<?php

namespace Tests\Feature\Api;

use App\Models\LessonCompletion;
use App\Models\LearningActivity;
use App\Models\LessonResource;
use App\Models\MaterialRead;
use App\Services\LessonProgressService;
use Illuminate\Support\Facades\DB;

/**
 * Study materials is gone, and stays gone.
 *
 * It shipped in 8235edd — a /materials list with read receipts that credited
 * learning minutes and auto-completed resource/article lessons — and was
 * removed two weeks later in 9bed5dd. That removal left the service method,
 * the MINUTES_MATERIAL_READ constant, the model and the table behind, with no
 * caller anywhere, so the behaviour had been dead for a fortnight while
 * looking alive in the source.
 *
 * The code is now gone too. These pin the two halves of that decision: nothing
 * credits a read any more, and the thing it used to automate still works by
 * the route students actually use.
 */
class StudyMaterialsRemovalTest extends ApiTestCase
{
    public function test_the_materials_endpoints_are_not_routed(): void
    {
        $student = $this->actingAsStudent();
        [$course, , $lessons] = $this->makeCourse();
        $this->enroll($student, $course);

        $resource = $lessons->first()->resources()->create([
            'name' => 'Worksheet', 'kind' => 'file',
            'file_path' => 'resources/w.pdf', 'file_type' => 'pdf', 'size_bytes' => 10,
        ]);

        $this->getJson('/api/v1/materials')->assertNotFound();
        $this->postJson("/api/v1/materials/{$resource->id}/read")->assertNotFound();
    }

    public function test_the_service_no_longer_offers_a_material_read_path(): void
    {
        // The method and its constant are what 9bed5dd should have taken.
        $this->assertFalse(
            method_exists(LessonProgressService::class, 'recordMaterialRead'),
            'recordMaterialRead is back — it has no caller and duplicates NoteRead',
        );
        $this->assertFalse(
            defined(LessonProgressService::class.'::MINUTES_MATERIAL_READ'),
            'MINUTES_MATERIAL_READ is back',
        );
    }

    /**
     * Opening a resource credits nothing.
     *
     * The Notes page opens a resource with a plain link and deliberately does
     * not post a read — asserted here as behaviour rather than trusted to the
     * portal, because the endpoint is what would have to exist for it to.
     */
    public function test_opening_a_resource_records_no_read_and_no_minutes(): void
    {
        $student = $this->actingAsStudent();
        [$course, , $lessons] = $this->makeCourse(2, [], ['type' => 'article']);
        $this->enroll($student, $course);

        $resource = $lessons->first()->resources()->create([
            'name' => 'Worksheet', 'kind' => 'file',
            'file_path' => 'resources/w.pdf', 'file_type' => 'pdf', 'size_bytes' => 10,
        ]);

        // The list still shows it; that half survived as the Notes page.
        $titles = collect($this->getJson('/api/v1/notes')->assertOk()->json('data'))->pluck('title');
        $this->assertContains('Worksheet', $titles);

        $this->assertSame(0, MaterialRead::count());
        $this->assertSame(0, LearningActivity::count(), 'no minutes are credited for opening a file');
        $this->assertSame(0, LessonCompletion::count(), 'and the lesson does not auto-complete');
    }

    /**
     * The thing auto-completion automated still works.
     *
     * This is why removal costs nothing: an article or resource lesson has
     * never needed the automation to be completable. The portal's complete
     * button is enabled for every lesson type — only video is gated, on watch
     * coverage — so the student marks it themselves and progress follows.
     */
    public function test_an_article_lesson_still_completes_and_moves_progress(): void
    {
        $student = $this->actingAsStudent();
        [$course, , $lessons] = $this->makeCourse(2, [], ['type' => 'article', 'duration_minutes' => 12]);
        $enrollment = $this->enroll($student, $course);

        $lessons->first()->resources()->create([
            'name' => 'Worksheet', 'kind' => 'file',
            'file_path' => 'resources/w.pdf', 'file_type' => 'pdf', 'size_bytes' => 10,
        ]);

        $this->postJson("/api/v1/courses/{$course->id}/lessons/{$lessons->first()->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.progress_percent', 50);

        $this->assertEquals(50.0, (float) $enrollment->fresh()->progress_percent);
        // The minutes path (bb3b9a6) still credits the lesson's own duration.
        $this->assertSame(12, LearningActivity::firstOrFail()->minutes);
    }

    /**
     * The table stays, and keeps whatever it holds.
     *
     * Read receipts record something a student actually did; they are not
     * derived state and cannot be recomputed. main is still at 8235edd — the
     * commit that ADDED the feature — so anything deployed from it may hold
     * real rows this branch cannot see. Dropping the table is a separate,
     * deliberate decision, not a side effect of deleting dead code.
     */
    public function test_existing_material_read_rows_are_left_alone(): void
    {
        $student = $this->actingAsStudent();
        [$course, , $lessons] = $this->makeCourse();
        $this->enroll($student, $course);

        $resource = $lessons->first()->resources()->create([
            'name' => 'Worksheet', 'kind' => 'file',
            'file_path' => 'resources/w.pdf', 'file_type' => 'pdf', 'size_bytes' => 10,
        ]);

        $receipt = MaterialRead::create([
            'user_id' => $student->id,
            'lesson_resource_id' => $resource->id,
            'read_at' => now()->subMonth(),
        ]);

        // Exercising everything that touches progress leaves the receipt be.
        $this->postJson("/api/v1/courses/{$course->id}/lessons/{$lessons->first()->id}/complete")->assertOk();
        $this->getJson('/api/v1/notes')->assertOk();
        $this->getJson('/api/v1/progress')->assertOk();

        $this->assertDatabaseHas('material_reads', ['id' => $receipt->id]);
        $this->assertSame(1, DB::table('material_reads')->count());
    }
}
