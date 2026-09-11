<?php

namespace Tests\Feature\Api;

use App\Models\LessonPrivateNote;
use App\Models\LessonResource;
use App\Models\Note;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The portal's Notes page (sidebar → Notes): instructor-uploaded note files
 * AND course-level resources, for enrolled courses only.
 *
 * Three different things share the word "note" in this codebase and only one
 * of them belongs here, so the absence of the other two is asserted, not
 * assumed: private notes are the student's own writing on the lesson player,
 * and lesson-level resources belong to the player's Resources tab.
 */
class NotesFeedTest extends ApiTestCase
{
    private function note(int $courseId, ?User $instructor = null, array $overrides = []): Note
    {
        $instructor ??= User::factory()->create();

        return Note::create(array_merge([
            'course_id' => $courseId,
            'instructor_id' => $instructor->id,
            'title' => 'Week 1 slides',
            'description' => 'Lecture deck',
            'file_path' => 'notes/week-1.pdf',
            'file_type' => 'pdf',
            'size_bytes' => 2048,
        ], $overrides));
    }

    public function test_an_enrolled_student_sees_note_files_and_course_resources_together(): void
    {
        [$course] = $this->makeCourse();
        $student = $this->actingAsStudent();
        $this->enroll($student, $course);

        $this->note($course->id);

        LessonResource::create([
            'course_id' => $course->id,
            'name' => 'Course handbook',
            'kind' => LessonResource::KIND_FILE,
            'file_path' => 'resources/handbook.pdf',
            'file_type' => 'pdf',
            'size_bytes' => 4096,
        ]);

        LessonResource::create([
            'course_id' => $course->id,
            'name' => 'Intro video',
            'kind' => LessonResource::KIND_LINK,
            'url' => 'https://www.youtube.com/watch?v=abc123',
        ]);

        $rows = collect($this->getJson('/api/v1/notes')->assertOk()->json('data'));

        $this->assertSame(
            ['Course handbook', 'Intro video', 'Week 1 slides'],
            $rows->pluck('title')->sort()->values()->all(),
        );
        $this->assertSame(
            ['note', 'resource', 'resource'],
            $rows->pluck('source')->sort()->values()->all(),
        );
    }

    public function test_a_link_row_carries_its_url_and_a_file_row_carries_a_file_url(): void
    {
        [$course] = $this->makeCourse();
        $student = $this->actingAsStudent();
        $this->enroll($student, $course);

        LessonResource::create([
            'course_id' => $course->id,
            'name' => 'Intro video',
            'kind' => LessonResource::KIND_LINK,
            'url' => 'https://youtu.be/abc123',
        ]);
        LessonResource::create([
            'course_id' => $course->id,
            'name' => 'Handbook',
            'kind' => LessonResource::KIND_FILE,
            'file_path' => 'resources/handbook.pdf',
            'file_type' => 'pdf',
            'size_bytes' => 4096,
        ]);

        $rows = collect($this->getJson('/api/v1/notes')->assertOk()->json('data'))
            ->keyBy('title');

        $link = $rows['Intro video'];
        $this->assertSame('link', $link['kind']);
        $this->assertSame('https://youtu.be/abc123', $link['url']);
        $this->assertNull($link['file_url'], 'a link has no stored file');
        $this->assertTrue($link['is_youtube']);
        $this->assertNull($link['size_bytes'], 'a link has no size to report');

        $file = $rows['Handbook'];
        $this->assertSame('file', $file['kind']);
        $this->assertNotNull($file['file_url']);
        $this->assertSame($file['file_url'], $file['url'], 'a file row opens its own stored file');
        $this->assertFalse($file['is_youtube']);
    }

    public function test_a_resource_on_a_course_the_student_is_not_enrolled_in_is_not_listed(): void
    {
        [$mine] = $this->makeCourse();
        [$theirs] = $this->makeCourse();

        $student = $this->actingAsStudent();
        $this->enroll($student, $mine);

        LessonResource::create([
            'course_id' => $mine->id,
            'name' => 'Mine',
            'kind' => LessonResource::KIND_LINK,
            'url' => 'https://example.com/mine',
        ]);
        LessonResource::create([
            'course_id' => $theirs->id,
            'name' => 'Theirs',
            'kind' => LessonResource::KIND_LINK,
            'url' => 'https://example.com/theirs',
        ]);
        $this->note($theirs->id, null, ['title' => 'Their slides']);

        $titles = collect($this->getJson('/api/v1/notes')->assertOk()->json('data'))->pluck('title');

        $this->assertContains('Mine', $titles);
        $this->assertNotContains('Theirs', $titles, 'enrollment is enforced on the query, not in the portal');
        $this->assertNotContains('Their slides', $titles);
    }

    public function test_a_lesson_level_resource_stays_on_the_lesson_player(): void
    {
        [$course, , $lessons] = $this->makeCourse();
        $student = $this->actingAsStudent();
        $this->enroll($student, $course);

        LessonResource::create([
            'lesson_id' => $lessons->first()->id,
            'name' => 'Lesson worksheet',
            'kind' => LessonResource::KIND_FILE,
            'file_path' => 'resources/worksheet.pdf',
            'file_type' => 'pdf',
            'size_bytes' => 512,
        ]);

        $titles = collect($this->getJson('/api/v1/notes')->assertOk()->json('data'))->pluck('title');

        $this->assertNotContains(
            'Lesson worksheet',
            $titles,
            'the Resources tab on the lesson player keeps lesson-level rows',
        );
    }

    /**
     * The model refuses a two-owner row, but the model is not in the path of a
     * raw insert or of a row that predates the guard. courseLevel() is what
     * keeps such a row off this page, so it is broken here on purpose to show
     * the scope is load-bearing and not decoration over the course_id filter.
     */
    public function test_a_row_carrying_both_owners_is_still_kept_off_the_page(): void
    {
        [$course, , $lessons] = $this->makeCourse();
        $student = $this->actingAsStudent();
        $this->enroll($student, $course);

        DB::table('lesson_resources')->insert([
            'lesson_id' => $lessons->first()->id,
            'course_id' => $course->id,
            'name' => 'Smuggled worksheet',
            'kind' => LessonResource::KIND_FILE,
            'file_path' => 'resources/worksheet.pdf',
            'file_type' => 'pdf',
            'size_bytes' => 512,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $titles = collect($this->getJson('/api/v1/notes')->assertOk()->json('data'))->pluck('title');

        $this->assertNotContains('Smuggled worksheet', $titles);
    }

    public function test_private_notes_never_appear_on_the_notes_page(): void
    {
        [$course, , $lessons] = $this->makeCourse();
        $student = $this->actingAsStudent();
        $this->enroll($student, $course);

        LessonPrivateNote::create([
            'lesson_id' => $lessons->first()->id,
            'user_id' => $student->id,
            'body' => 'My own private thinking about lesson one.',
        ]);

        $payload = $this->getJson('/api/v1/notes')->assertOk()->json('data');

        $this->assertSame([], $payload, 'a private note is the student\'s own writing, not published material');
        $this->assertStringNotContainsString('private thinking', json_encode($payload));
    }

    public function test_every_row_carries_the_fields_the_portal_renders(): void
    {
        [$course] = $this->makeCourse();
        $student = $this->actingAsStudent();
        $this->enroll($student, $course);

        LessonResource::create([
            'course_id' => $course->id,
            'name' => 'Handbook',
            'kind' => LessonResource::KIND_FILE,
            'file_path' => 'resources/handbook.pdf',
            'file_type' => 'pdf',
            'size_bytes' => 4096,
        ]);
        $this->note($course->id);

        foreach ($this->getJson('/api/v1/notes')->assertOk()->json('data') as $row) {
            foreach (['id', 'source', 'kind', 'title', 'url', 'file_url', 'file_type', 'is_youtube', 'uploaded_at', 'course'] as $key) {
                $this->assertArrayHasKey($key, $row, "row is missing {$key}");
            }
            $this->assertIsInt($row['id'], 'the read endpoint takes a numeric id');
            $this->assertNotNull($row['course']['title']);
        }
    }

    public function test_marking_a_note_read_still_works_alongside_resources(): void
    {
        [$course] = $this->makeCourse();
        $student = $this->actingAsStudent();
        $this->enroll($student, $course);

        LessonResource::create([
            'course_id' => $course->id,
            'name' => 'Handbook',
            'kind' => LessonResource::KIND_LINK,
            'url' => 'https://example.com/handbook',
        ]);
        $note = $this->note($course->id);

        $this->postJson("/api/v1/notes/{$note->id}/read")
            ->assertOk()
            ->assertJsonPath('data.is_read', true);

        $this->assertDatabaseHas('note_reads', [
            'user_id' => $student->id,
            'note_id' => $note->id,
        ]);
    }
}
