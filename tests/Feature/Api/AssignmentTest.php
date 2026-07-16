<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class AssignmentTest extends ApiTestCase
{
    protected function makeAssignment(array $overrides = []): array
    {
        [$course] = $this->makeCourse(1);

        $assignment = Assignment::create(array_merge([
            'course_id' => $course->id,
            'title' => 'Build something',
            'description' => 'A description.',
            'instructions' => '<p>Do the thing.</p>',
            'due_at' => now()->addDays(3),
            'max_score' => 100,
        ], $overrides));

        return [$course, $assignment];
    }

    public function test_list_is_scoped_to_enrolled_courses_with_derived_status(): void
    {
        $user = $this->actingAsStudent();
        [$course, $pending] = $this->makeAssignment();
        $this->enroll($user, $course);

        Assignment::create([
            'course_id' => $course->id,
            'title' => 'Too late',
            'due_at' => now()->subDay(),
            'max_score' => 50,
        ]);

        // Assignment in a course the student is not enrolled in stays hidden.
        $this->makeAssignment(['title' => 'Hidden']);

        $this->getJson('/api/v1/assignments')->assertOk()->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/assignments?status=pending')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.course.id', $course->id);

        $this->getJson('/api/v1/assignments?status=overdue')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'overdue');
    }

    public function test_detail_requires_enrollment(): void
    {
        $this->actingAsStudent();
        [, $assignment] = $this->makeAssignment();

        $this->getJson("/api/v1/assignments/{$assignment->id}")->assertForbidden();
    }

    public function test_submission_with_content_and_late_flag(): void
    {
        $user = $this->actingAsStudent();
        [$course, $assignment] = $this->makeAssignment(['due_at' => now()->subHour()]);
        $this->enroll($user, $course);

        $this->postJson("/api/v1/assignments/{$assignment->id}/submissions", [
            'content' => 'My essay.',
        ])->assertCreated()
            ->assertJsonPath('data.is_late', true)
            ->assertJsonPath('data.content', 'My essay.');

        $this->assertDatabaseHas('assignment_submissions', [
            'assignment_id' => $assignment->id,
            'user_id' => $user->id,
            'is_late' => true,
        ]);

        $this->getJson("/api/v1/assignments/{$assignment->id}")->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.submission.content', 'My essay.');
    }

    public function test_submission_with_file_upload(): void
    {
        Storage::fake('public');

        $user = $this->actingAsStudent();
        [$course, $assignment] = $this->makeAssignment();
        $this->enroll($user, $course);

        $this->post("/api/v1/assignments/{$assignment->id}/submissions", [
            'file' => UploadedFile::fake()->create('homework.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.file_name', 'homework.pdf')
            ->assertJsonPath('data.is_late', false);

        $submission = AssignmentSubmission::first();
        Storage::disk('public')->assertExists($submission->file_path);
    }

    public function test_submission_requires_content_or_file_and_caps_file_size(): void
    {
        $user = $this->actingAsStudent();
        [$course, $assignment] = $this->makeAssignment();
        $this->enroll($user, $course);

        $this->postJson("/api/v1/assignments/{$assignment->id}/submissions", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content', 'file']);

        $this->post("/api/v1/assignments/{$assignment->id}/submissions", [
            'file' => UploadedFile::fake()->create('big.zip', 10241),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_resubmission_is_forbidden_once_graded(): void
    {
        $user = $this->actingAsStudent();
        [$course, $assignment] = $this->makeAssignment();
        $this->enroll($user, $course);
        $grader = User::factory()->create();

        AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'user_id' => $user->id,
            'content' => 'v1',
            'submitted_at' => now()->subDay(),
            'score' => 80,
            'graded_at' => now(),
            'graded_by' => $grader->id,
        ]);

        $this->postJson("/api/v1/assignments/{$assignment->id}/submissions", [
            'content' => 'v2',
        ])->assertForbidden();

        $this->getJson("/api/v1/assignments/{$assignment->id}")->assertOk()
            ->assertJsonPath('data.status', 'graded')
            ->assertJsonPath('data.submission.score', 80);
    }

    public function test_resubmission_before_grading_updates_in_place(): void
    {
        $user = $this->actingAsStudent();
        [$course, $assignment] = $this->makeAssignment();
        $this->enroll($user, $course);

        $this->postJson("/api/v1/assignments/{$assignment->id}/submissions", ['content' => 'v1'])->assertCreated();
        $this->postJson("/api/v1/assignments/{$assignment->id}/submissions", ['content' => 'v2'])->assertCreated();

        $this->assertSame(1, AssignmentSubmission::count());
        $this->assertSame('v2', AssignmentSubmission::first()->content);
    }
}
