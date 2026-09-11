<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Every comment edit and delete lands in the one audit trail.
 *
 * Through the shared Auditable concern on the model, not by logging from the
 * controller, so an edit is recorded wherever it comes from — a route added
 * later, a console command, a fix run in tinker — and not only from the two
 * endpoints that exist today.
 *
 * The OLD BODY is the point. A row saying a comment changed, without saying
 * what it said, is a row that cannot answer the question anyone would ask it.
 */
class CommentAuditTest extends ApiTestCase
{
    /** @return array{0: \App\Models\Course, 1: \App\Models\Lesson} */
    protected function courseWithLesson(): array
    {
        [$course, , $lessons] = $this->makeCourse(1);

        return [$course, $lessons->first()];
    }

    protected function commentRows(): \Illuminate\Support\Collection
    {
        return AuditLog::where('module', 'comments')->orderBy('id')->get();
    }

    public function test_a_student_editing_their_own_comment_is_audited_as_the_student(): void
    {
        $author = $this->actingAsStudent();
        [$course, $lesson] = $this->courseWithLesson();
        $this->enroll($author, $course);

        $id = $this->postJson("/api/v1/lessons/{$lesson->id}/comments", ['body' => 'first draft'])
            ->assertCreated()->json('data.id');

        $this->putJson("/api/v1/lessons/{$lesson->id}/comments/{$id}", ['body' => 'second draft'])
            ->assertOk();

        $row = $this->commentRows()->firstWhere('action', 'updated');

        $this->assertNotNull($row, 'the edit must be audited');
        $this->assertSame($author->id, $row->user_id);
        $this->assertStringContainsString('student', (string) $row->user_role);
        $this->assertSame($id, $row->record_id);
        // Old and new, both kept.
        $this->assertSame('first draft', $row->old_values['body']);
        $this->assertSame('second draft', $row->new_values['body']);
        // And the context that tells the two actors apart.
        $this->assertTrue($row->new_values['by_owner']);
        $this->assertSame($author->id, $row->new_values['author_id']);
        $this->assertSame($lesson->id, $row->new_values['lesson_id']);
    }

    public function test_a_student_deleting_their_own_comment_is_audited_with_what_it_said(): void
    {
        $author = $this->actingAsStudent();
        [$course, $lesson] = $this->courseWithLesson();
        $this->enroll($author, $course);

        $id = $this->postJson("/api/v1/lessons/{$lesson->id}/comments", ['body' => 'withdrawn question'])
            ->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/lessons/{$lesson->id}/comments/{$id}")->assertNoContent();

        $row = $this->commentRows()->firstWhere('action', 'deleted');

        $this->assertNotNull($row);
        $this->assertSame('withdrawn question', $row->old_values['body']);
        $this->assertTrue($row->new_values['by_owner']);
    }

    public function test_a_super_admin_editing_someone_elses_comment_is_told_apart_from_the_owner(): void
    {
        /*
         * The distinction the log has to carry. Both rows are action=updated
         * on module=comments, so what separates them is the ACTOR (user_name
         * and user_role, which AuditLogger already records) and the SUBJECT
         * plus `by_owner`, which the model's auditContext adds.
         */
        $author = $this->actingAsStudent();
        [$course, $lesson] = $this->courseWithLesson();
        $this->enroll($author, $course);

        $id = $this->postJson("/api/v1/lessons/{$lesson->id}/comments", ['body' => 'the original'])
            ->assertCreated()->json('data.id');

        $superAdmin = User::factory()->create(['name' => 'The Owner']);
        $superAdmin->assignRole('super-admin');
        $this->enroll($superAdmin, $course);
        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/v1/lessons/{$lesson->id}/comments/{$id}", ['body' => 'moderated text'])
            ->assertOk();

        $row = $this->commentRows()->where('action', 'updated')->last();

        $this->assertSame($superAdmin->id, $row->user_id, 'the actor is the super-admin');
        $this->assertStringContainsString('super-admin', (string) $row->user_role);
        $this->assertSame($author->id, $row->new_values['author_id'], 'the subject is still the student');
        $this->assertSame($author->name, $row->new_values['author_name']);
        $this->assertFalse($row->new_values['by_owner'], 'this was not the owner editing');
        $this->assertSame('the original', $row->old_values['body']);
        $this->assertSame('moderated text', $row->new_values['body']);
    }

    public function test_the_two_rows_are_distinguishable_side_by_side(): void
    {
        // Read as a pair, which is how an auditor would meet them.
        $author = $this->actingAsStudent();
        [$course, $lesson] = $this->courseWithLesson();
        $this->enroll($author, $course);

        $id = $this->postJson("/api/v1/lessons/{$lesson->id}/comments", ['body' => 'v1'])
            ->assertCreated()->json('data.id');
        $this->putJson("/api/v1/lessons/{$lesson->id}/comments/{$id}", ['body' => 'v2'])->assertOk();

        $superAdmin = User::factory()->create(['name' => 'Root']);
        $superAdmin->assignRole('super-admin');
        $this->enroll($superAdmin, $course);
        Sanctum::actingAs($superAdmin);
        $this->putJson("/api/v1/lessons/{$lesson->id}/comments/{$id}", ['body' => 'v3'])->assertOk();

        $updates = $this->commentRows()->where('action', 'updated')->values();

        $this->assertCount(2, $updates);
        $this->assertTrue($updates[0]->new_values['by_owner']);
        $this->assertFalse($updates[1]->new_values['by_owner']);
        $this->assertNotSame($updates[0]->user_id, $updates[1]->user_id);
    }

    public function test_a_refused_edit_writes_no_audit_row(): void
    {
        // A 403 is not an event. Logging attempts would fill the trail with
        // rows that describe nothing having happened.
        $author = $this->actingAsStudent();
        [$course, $lesson] = $this->courseWithLesson();
        $this->enroll($author, $course);

        $id = $this->postJson("/api/v1/lessons/{$lesson->id}/comments", ['body' => 'mine'])
            ->assertCreated()->json('data.id');

        $classmate = $this->student();
        $this->enroll($classmate, $course);
        $this->actingAsStudent($classmate);

        $this->putJson("/api/v1/lessons/{$lesson->id}/comments/{$id}", ['body' => 'hijacked'])
            ->assertForbidden();

        $this->assertCount(0, $this->commentRows()->where('action', 'updated'));
        $this->assertSame('mine', Comment::find($id)->body);
    }

    public function test_posting_a_comment_is_audited_too(): void
    {
        // Free from the same concern, and it means the trail holds the whole
        // life of a comment rather than only its alterations.
        $author = $this->actingAsStudent();
        [$course, $lesson] = $this->courseWithLesson();
        $this->enroll($author, $course);

        $this->postJson("/api/v1/lessons/{$lesson->id}/comments", ['body' => 'hello'])->assertCreated();

        $row = $this->commentRows()->firstWhere('action', 'created');

        $this->assertNotNull($row);
        $this->assertSame('hello', $row->new_values['body']);
        $this->assertTrue($row->new_values['by_owner']);
    }
}
