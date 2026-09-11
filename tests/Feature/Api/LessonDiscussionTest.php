<?php

namespace Tests\Feature\Api;

use App\Models\Comment;
use App\Models\LessonPrivateNote;
use App\Models\User;

/**
 * The discussion under a lesson, and the notebook beside it.
 *
 * Two different privacy rules on one screen, which is exactly why they are
 * tested together: comments are public to the cohort, notes are visible to one
 * person, and it must not be possible to confuse the two.
 *
 * Ownership is enforced in the controller, not by hiding a button. This
 * codebase has been bitten by a hidden control three times, so every "may not"
 * below goes through a real request and asserts a status code.
 */
class LessonDiscussionTest extends ApiTestCase
{
    /** @return array{0: \App\Models\Course, 1: \App\Models\Lesson} */
    protected function courseWithLesson(): array
    {
        [$course, , $lessons] = $this->makeCourse(1);

        return [$course, $lessons->first()];
    }

    // ---------------------------------------------------------- comments

    public function test_an_enrolled_student_posts_a_comment_others_can_read(): void
    {
        $author = $this->actingAsStudent();
        [$course, $lesson] = $this->courseWithLesson();
        $this->enroll($author, $course);

        $this->postJson("/api/v1/lessons/{$lesson->id}/comments", ['body' => 'Why is this O(n)?'])
            ->assertCreated();

        // A different enrolled student on the same course sees it.
        $classmate = $this->student();
        $this->enroll($classmate, $course);
        $this->actingAsStudent($classmate);

        $this->getJson("/api/v1/lessons/{$lesson->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Why is this O(n)?');
    }

    public function test_a_student_edits_their_own_comment(): void
    {
        $author = $this->actingAsStudent();
        [$course, $lesson] = $this->courseWithLesson();
        $this->enroll($author, $course);

        $id = $this->postJson("/api/v1/lessons/{$lesson->id}/comments", ['body' => 'first draft'])
            ->assertCreated()->json('data.id');

        $this->putJson("/api/v1/lessons/{$lesson->id}/comments/{$id}", ['body' => 'second draft'])
            ->assertOk()
            ->assertJsonPath('data.body', 'second draft');

        $this->assertSame('second draft', Comment::find($id)->body);
    }

    public function test_a_student_cannot_edit_someone_elses_comment(): void
    {
        $author = $this->actingAsStudent();
        [$course, $lesson] = $this->courseWithLesson();
        $this->enroll($author, $course);

        $id = $this->postJson("/api/v1/lessons/{$lesson->id}/comments", ['body' => 'mine'])
            ->assertCreated()->json('data.id');

        // Enrolled on the same course — so this is ownership, not access.
        $classmate = $this->student();
        $this->enroll($classmate, $course);
        $this->actingAsStudent($classmate);

        $this->putJson("/api/v1/lessons/{$lesson->id}/comments/{$id}", ['body' => 'hijacked'])
            ->assertForbidden();

        $this->deleteJson("/api/v1/lessons/{$lesson->id}/comments/{$id}")
            ->assertForbidden();

        $this->assertSame('mine', Comment::find($id)->body);
    }

    public function test_a_student_deletes_their_own_comment_without_taking_the_replies(): void
    {
        $author = $this->actingAsStudent();
        [$course, $lesson] = $this->courseWithLesson();
        $this->enroll($author, $course);

        $id = $this->postJson("/api/v1/lessons/{$lesson->id}/comments", ['body' => 'opening question'])
            ->assertCreated()->json('data.id');

        $classmate = $this->student();
        $this->enroll($classmate, $course);
        $this->actingAsStudent($classmate);
        $replyId = $this->postJson("/api/v1/lessons/{$lesson->id}/comments", [
            'body' => 'here is the answer', 'parent_id' => $id,
        ])->assertCreated()->json('data.id');

        $this->actingAsStudent($author);
        $this->deleteJson("/api/v1/lessons/{$lesson->id}/comments/{$id}")->assertNoContent();

        $this->assertSoftDeleted('comments', ['id' => $id]);
        // Withdrawing a question must not delete the answer someone else wrote.
        $this->assertNotNull(Comment::find($replyId));
    }

    public function test_a_student_who_is_not_enrolled_cannot_read_or_post(): void
    {
        $author = $this->actingAsStudent();
        [$course, $lesson] = $this->courseWithLesson();
        $this->enroll($author, $course);
        $this->postJson("/api/v1/lessons/{$lesson->id}/comments", ['body' => 'cohort only'])->assertCreated();

        $outsider = $this->student();
        $this->actingAsStudent($outsider);

        $this->getJson("/api/v1/lessons/{$lesson->id}/comments")->assertForbidden();
        $this->postJson("/api/v1/lessons/{$lesson->id}/comments", ['body' => 'hello'])->assertForbidden();
    }

    public function test_a_preview_lesson_no_longer_opens_its_discussion_to_everyone(): void
    {
        /*
         * The guard used to be `$enrolled || $lesson->is_preview`, so the
         * thread under any preview lesson was readable AND postable by every
         * signed-in user on the platform. A preview is a free sample of the
         * teaching, not of the cohort.
         */
        [$course, $lesson] = $this->courseWithLesson();
        $lesson->update(['is_preview' => true]);

        $outsider = $this->actingAsStudent();

        $this->getJson("/api/v1/lessons/{$lesson->id}/comments")->assertForbidden();
        $this->postJson("/api/v1/lessons/{$lesson->id}/comments", ['body' => 'walking in'])->assertForbidden();
    }

    public function test_losing_enrollment_ends_the_right_to_edit(): void
    {
        // Two questions, both asked: still in the course, and still the author.
        $author = $this->actingAsStudent();
        [$course, $lesson] = $this->courseWithLesson();
        $enrollment = $this->enroll($author, $course);

        $id = $this->postJson("/api/v1/lessons/{$lesson->id}/comments", ['body' => 'mine'])
            ->assertCreated()->json('data.id');

        $enrollment->delete();

        $this->putJson("/api/v1/lessons/{$lesson->id}/comments/{$id}", ['body' => 'edited later'])
            ->assertForbidden();
    }

    public function test_a_comment_from_another_lesson_is_not_found(): void
    {
        $author = $this->actingAsStudent();
        [$course, $lesson] = $this->courseWithLesson();
        [$otherCourse, $otherLesson] = $this->courseWithLesson();
        $this->enroll($author, $course);
        $this->enroll($author, $otherCourse);

        $id = $this->postJson("/api/v1/lessons/{$lesson->id}/comments", ['body' => 'mine'])
            ->assertCreated()->json('data.id');

        $this->putJson("/api/v1/lessons/{$otherLesson->id}/comments/{$id}", ['body' => 'x'])
            ->assertNotFound();
    }

    // ----------------------------------------------------- private notes

    public function test_a_student_writes_a_note_only_they_can_read(): void
    {
        $author = $this->actingAsStudent();
        [$course, $lesson] = $this->courseWithLesson();
        $this->enroll($author, $course);

        $this->putJson("/api/v1/lessons/{$lesson->id}/private-note", ['body' => 'revise the join syntax'])
            ->assertOk()
            ->assertJsonPath('data.body', 'revise the join syntax');

        $this->getJson("/api/v1/lessons/{$lesson->id}/private-note")
            ->assertOk()
            ->assertJsonPath('data.body', 'revise the join syntax');

        // A classmate on the same course gets their OWN empty note, not this.
        $classmate = $this->student();
        $this->enroll($classmate, $course);
        $this->actingAsStudent($classmate);

        $this->getJson("/api/v1/lessons/{$lesson->id}/private-note")
            ->assertOk()
            ->assertJsonPath('data.body', '');
    }

    public function test_a_note_never_appears_in_the_public_discussion(): void
    {
        // The two live side by side on one screen; confusing them is the
        // failure that would matter most.
        $author = $this->actingAsStudent();
        [$course, $lesson] = $this->courseWithLesson();
        $this->enroll($author, $course);

        $this->putJson("/api/v1/lessons/{$lesson->id}/private-note", ['body' => 'SECRET-NOTE-TEXT'])->assertOk();

        $classmate = $this->student();
        $this->enroll($classmate, $course);
        $this->actingAsStudent($classmate);

        $this->assertStringNotContainsString(
            'SECRET-NOTE-TEXT',
            $this->getJson("/api/v1/lessons/{$lesson->id}/comments")->assertOk()->getContent(),
        );
        $this->assertStringNotContainsString(
            'SECRET-NOTE-TEXT',
            $this->getJson("/api/v1/courses/{$course->id}/lessons/{$lesson->id}")->assertOk()->getContent(),
        );
    }

    public function test_an_admin_cannot_read_a_students_note_through_the_api(): void
    {
        // Stated plainly and tested: no role reaches another person's notebook
        // through this application. There is no admin route that reads the
        // table at all — this asserts the student route does not become one.
        $author = $this->actingAsStudent();
        [$course, $lesson] = $this->courseWithLesson();
        $this->enroll($author, $course);
        $this->putJson("/api/v1/lessons/{$lesson->id}/private-note", ['body' => 'PRIVATE'])->assertOk();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        \Laravel\Sanctum\Sanctum::actingAs($admin);

        // An admin is not enrolled, so they are refused outright; and even
        // enrolled they would see their own empty note, never this one.
        $this->getJson("/api/v1/lessons/{$lesson->id}/private-note")->assertForbidden();

        $this->enroll($admin, $course);
        $this->getJson("/api/v1/lessons/{$lesson->id}/private-note")
            ->assertOk()
            ->assertJsonPath('data.body', '');

        $this->assertSame(1, LessonPrivateNote::count(), 'the original note is untouched');
    }

    public function test_clearing_the_box_removes_the_note_rather_than_storing_blank(): void
    {
        $author = $this->actingAsStudent();
        [$course, $lesson] = $this->courseWithLesson();
        $this->enroll($author, $course);

        $this->putJson("/api/v1/lessons/{$lesson->id}/private-note", ['body' => 'temporary'])->assertOk();
        $this->putJson("/api/v1/lessons/{$lesson->id}/private-note", ['body' => '   '])->assertNoContent();

        $this->assertSame(0, LessonPrivateNote::count());
        $this->getJson("/api/v1/lessons/{$lesson->id}/private-note")->assertJsonPath('data.body', '');
    }

    public function test_saving_twice_keeps_one_note_not_two(): void
    {
        // The unique index is what makes the upsert safe under a double-submit.
        $author = $this->actingAsStudent();
        [$course, $lesson] = $this->courseWithLesson();
        $this->enroll($author, $course);

        $this->putJson("/api/v1/lessons/{$lesson->id}/private-note", ['body' => 'one'])->assertOk();
        $this->putJson("/api/v1/lessons/{$lesson->id}/private-note", ['body' => 'two'])->assertOk();

        $this->assertSame(1, LessonPrivateNote::count());
        $this->assertSame('two', LessonPrivateNote::first()->body);
    }

    public function test_a_non_enrolled_student_cannot_keep_notes_on_the_lesson(): void
    {
        [, $lesson] = $this->courseWithLesson();
        $this->actingAsStudent();

        $this->getJson("/api/v1/lessons/{$lesson->id}/private-note")->assertForbidden();
        $this->putJson("/api/v1/lessons/{$lesson->id}/private-note", ['body' => 'x'])->assertForbidden();
    }
}
