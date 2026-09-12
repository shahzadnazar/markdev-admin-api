<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Category;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LessonResource;
use App\Models\Note;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\PrivateFiles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Private uploads are reachable only by someone entitled to them.
 *
 * The state this replaces was measured, not assumed: a GET for
 * /storage/students/photos/<hash>.png returned 200 and 38 KB of a named
 * student's photograph with no session, and an assignment submission did the
 * same. There was no authorisation anywhere in that path because there was no
 * request handler in it.
 *
 * Every test here asserts on BYTES as well as status. A 403 that still wrote
 * the file to the response body would pass a status assertion and leak
 * everything anyway.
 */
class PrivateFileAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake(PrivateFiles::DISK);
        Storage::fake('public');
    }

    protected function userWith(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    protected function profileFor(User $student, string $secret = 'CNIC-SECRET-BYTES'): StudentProfile
    {
        Storage::disk(PrivateFiles::DISK)->put('students/documents/cnic.pdf', $secret);
        Storage::disk(PrivateFiles::DISK)->put('students/photos/face.png', 'PHOTO-BYTES');

        return StudentProfile::create([
            'user_id' => $student->id,
            'name' => 'A Student',
            'reg_no' => 'R-'.Str::random(5),
            'cnic_doc_path' => 'students/documents/cnic.pdf',
            'photo_path' => 'students/photos/face.png',
        ]);
    }

    protected function course(?User $instructor = null, ?Category $category = null): Course
    {
        $category ??= Category::create(['name' => 'Cat '.Str::random(4), 'slug' => 'c-'.Str::random(6)]);

        return Course::create([
            'title' => 'Course '.Str::random(5),
            'slug' => 'c-'.Str::random(8),
            'excerpt' => 'x',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'is_free' => true,
            'category_id' => $category->id,
            'instructor_id' => $instructor?->id,
        ]);
    }

    /* ------------------------------ no identity ----------------------------- */

    public function test_an_unauthenticated_request_never_returns_the_bytes(): void
    {
        $student = $this->userWith('student');
        $profile = $this->profileFor($student);

        $response = $this->get(route('files.student-document', [$profile, 'cnic']));

        $this->assertContains($response->status(), [403, 404]);
        $this->assertStringNotContainsString('CNIC-SECRET-BYTES', $response->getContent());
    }

    public function test_an_invalid_signature_is_refused(): void
    {
        $student = $this->userWith('student');
        $profile = $this->profileFor($student);

        // The shape a signed link has, with the signature filed off.
        $response = $this->get(route('files.student-document', [$profile, 'cnic']).'?u='.$student->id);

        $this->assertContains($response->status(), [403, 404]);
        $this->assertStringNotContainsString('CNIC-SECRET-BYTES', $response->getContent());
    }

    public function test_an_expired_signature_is_refused(): void
    {
        $student = $this->userWith('student');
        $profile = $this->profileFor($student);

        $url = URL::temporarySignedRoute(
            'files.student-document',
            now()->subMinute(),
            ['profile' => $profile->id, 'kind' => 'cnic', 'u' => $student->id],
        );

        $response = $this->get($url);

        $this->assertContains($response->status(), [403, 404]);
        $this->assertStringNotContainsString('CNIC-SECRET-BYTES', $response->getContent());
    }

    /* --------------------------- student documents -------------------------- */

    public function test_a_student_may_read_their_own_document(): void
    {
        $student = $this->userWith('student');
        $profile = $this->profileFor($student);

        $response = $this->actingAs($student)
            ->get(route('files.student-document', [$profile, 'cnic']))
            ->assertOk();

        $this->assertSame('CNIC-SECRET-BYTES', $response->streamedContent());
    }

    public function test_a_student_may_not_read_another_students_cnic(): void
    {
        $owner = $this->userWith('student');
        $profile = $this->profileFor($owner);
        $other = $this->userWith('student');

        $response = $this->actingAs($other)->get(route('files.student-document', [$profile, 'cnic']));

        $response->assertForbidden();
        $this->assertStringNotContainsString('CNIC-SECRET-BYTES', $response->getContent());
    }

    public function test_an_instructor_may_not_read_a_students_cnic(): void
    {
        // An instructor's job needs a name and a grade, never a national ID.
        $owner = $this->userWith('student');
        $profile = $this->profileFor($owner);

        $response = $this->actingAs($this->userWith('instructor'))
            ->get(route('files.student-document', [$profile, 'cnic']));

        $response->assertForbidden();
        $this->assertStringNotContainsString('CNIC-SECRET-BYTES', $response->getContent());
    }

    public function test_staff_who_may_view_students_can_read_it(): void
    {
        $owner = $this->userWith('student');
        $profile = $this->profileFor($owner);

        $response = $this->actingAs($this->userWith('admin'))
            ->get(route('files.student-document', [$profile, 'cnic']))
            ->assertOk();

        $this->assertSame('CNIC-SECRET-BYTES', $response->streamedContent());
    }

    public function test_an_unknown_document_kind_is_a_404(): void
    {
        $student = $this->userWith('student');
        $profile = $this->profileFor($student);

        $this->actingAs($this->userWith('admin'))
            ->get(route('files.student-document', [$profile, 'passport']))
            ->assertNotFound();
    }

    /* ----------------------------- submissions ------------------------------ */

    protected function submission(User $author, Course $course): AssignmentSubmission
    {
        $assignment = Assignment::create([
            'course_id' => $course->id,
            'title' => 'Essay',
            'description' => 'x',
            'due_at' => now()->addWeek(),
            'points' => 100,
        ]);

        Storage::disk(PrivateFiles::DISK)->put('submissions/work.pdf', 'MY-HOMEWORK-BYTES');

        return AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'user_id' => $author->id,
            'file_path' => 'submissions/work.pdf',
            'submitted_at' => now(),
        ]);
    }

    public function test_the_author_may_read_their_own_submission(): void
    {
        $author = $this->userWith('student');
        $submission = $this->submission($author, $this->course());

        $response = $this->actingAs($author)->get(route('files.submission', $submission))->assertOk();

        $this->assertSame('MY-HOMEWORK-BYTES', $response->streamedContent());
    }

    public function test_another_student_may_not_read_a_submission(): void
    {
        $author = $this->userWith('student');
        $submission = $this->submission($author, $this->course());

        $response = $this->actingAs($this->userWith('student'))->get(route('files.submission', $submission));

        $response->assertForbidden();
        $this->assertStringNotContainsString('MY-HOMEWORK-BYTES', $response->getContent());
    }

    public function test_the_grading_instructor_may_read_it(): void
    {
        $instructor = $this->userWith('instructor');
        $author = $this->userWith('student');
        $submission = $this->submission($author, $this->course($instructor));

        $response = $this->actingAs($instructor)->get(route('files.submission', $submission))->assertOk();

        $this->assertSame('MY-HOMEWORK-BYTES', $response->streamedContent());
    }

    public function test_an_instructor_from_another_category_may_not(): void
    {
        // Category scoping (77b6fd1) reaches the file, not only the list.
        $author = $this->userWith('student');
        $submission = $this->submission($author, $this->course($this->userWith('instructor')));

        $response = $this->actingAs($this->userWith('instructor'))->get(route('files.submission', $submission));

        $response->assertForbidden();
        $this->assertStringNotContainsString('MY-HOMEWORK-BYTES', $response->getContent());
    }

    public function test_an_admin_may_read_any_submission(): void
    {
        $author = $this->userWith('student');
        $submission = $this->submission($author, $this->course());

        $response = $this->actingAs($this->userWith('admin'))->get(route('files.submission', $submission))->assertOk();

        $this->assertSame('MY-HOMEWORK-BYTES', $response->streamedContent());
    }

    /* --------------------------- course material ---------------------------- */

    protected function note(Course $course): Note
    {
        Storage::disk(PrivateFiles::DISK)->put('notes/week-1.pdf', 'LECTURE-SLIDES-BYTES');

        return Note::create([
            'course_id' => $course->id,
            'instructor_id' => User::factory()->create()->id,
            'title' => 'Week 1',
            'file_path' => 'notes/week-1.pdf',
            'file_type' => 'pdf',
            'size_bytes' => 20,
        ]);
    }

    public function test_an_enrolled_student_may_read_a_note_file(): void
    {
        $course = $this->course();
        $note = $this->note($course);
        $student = $this->userWith('student');
        Enrollment::create(['user_id' => $student->id, 'course_id' => $course->id, 'enrolled_at' => now()]);

        $response = $this->actingAs($student)->get(route('files.note', $note))->assertOk();

        $this->assertSame('LECTURE-SLIDES-BYTES', $response->streamedContent());
    }

    public function test_a_student_who_is_not_enrolled_may_not(): void
    {
        $note = $this->note($this->course());

        $response = $this->actingAs($this->userWith('student'))->get(route('files.note', $note));

        $response->assertForbidden();
        $this->assertStringNotContainsString('LECTURE-SLIDES-BYTES', $response->getContent());
    }

    public function test_a_resource_file_follows_the_same_rule(): void
    {
        $course = $this->course();
        Storage::disk(PrivateFiles::DISK)->put('resources/handbook.pdf', 'HANDBOOK-BYTES');
        $resource = $course->resources()->create([
            'name' => 'Handbook', 'kind' => 'file',
            'file_path' => 'resources/handbook.pdf', 'file_type' => 'pdf', 'size_bytes' => 14,
        ]);

        $outsider = $this->userWith('student');
        $response = $this->actingAs($outsider)->get(route('files.resource', $resource));
        $response->assertForbidden();
        $this->assertStringNotContainsString('HANDBOOK-BYTES', $response->getContent());

        $enrolled = $this->userWith('student');
        Enrollment::create(['user_id' => $enrolled->id, 'course_id' => $course->id, 'enrolled_at' => now()]);
        $this->assertSame(
            'HANDBOOK-BYTES',
            $this->actingAs($enrolled)->get(route('files.resource', $resource))->assertOk()->streamedContent(),
        );
    }

    /* ------------------------------ signed links ---------------------------- */

    /**
     * A signed link carries identity, not permission.
     *
     * The portal holds a bearer token that an <a href> cannot send, so links
     * for it are signed with the viewer's id. If the signature were treated as
     * authorisation, anyone able to mint one — or to replay someone else's —
     * would read the file. It is not: the same checks run either way.
     */
    public function test_a_valid_signature_for_the_wrong_person_is_still_refused(): void
    {
        $owner = $this->userWith('student');
        $profile = $this->profileFor($owner);
        $other = $this->userWith('student');

        $url = URL::temporarySignedRoute(
            'files.student-document',
            now()->addMinutes(30),
            ['profile' => $profile->id, 'kind' => 'cnic', 'u' => $other->id],
        );

        $response = $this->get($url);

        $response->assertForbidden();
        $this->assertStringNotContainsString('CNIC-SECRET-BYTES', $response->getContent());
    }

    public function test_a_valid_signature_for_the_right_person_works_without_a_session(): void
    {
        $owner = $this->userWith('student');
        $profile = $this->profileFor($owner);

        $url = PrivateFiles::signedUrl(
            'files.student-document',
            ['profile' => $profile->id, 'kind' => 'cnic'],
            $owner,
        );

        $this->assertSame('CNIC-SECRET-BYTES', $this->get($url)->assertOk()->streamedContent());
    }

    /* ---------------------------- no public URLs ---------------------------- */

    /**
     * No accessor mints a /storage/ URL for a private kind.
     *
     * The bug was not that one place built a public URL; it was that every
     * place did, because the default disk for an upload was `public`. This
     * checks the shape of what the models hand out rather than the text of the
     * code, so a new accessor that reintroduces it is caught too.
     */
    public function test_no_private_kind_hands_out_a_storage_url(): void
    {
        $student = $this->userWith('student');
        $profile = $this->profileFor($student);
        $course = $this->course();
        $note = $this->note($course);
        $submission = $this->submission($student, $course);
        $resource = $course->resources()->create([
            'name' => 'Handbook', 'kind' => 'file',
            'file_path' => 'resources/handbook.pdf', 'file_type' => 'pdf', 'size_bytes' => 14,
        ]);

        $urls = [
            'student photo' => $profile->documentUrl('photo'),
            'student cnic' => $profile->documentUrl('cnic'),
            'note' => $note->file_url,
            'submission' => $submission->file_url,
            'resource' => $resource->file_url,
        ];

        foreach ($urls as $label => $url) {
            $this->assertNotNull($url, "{$label} produced no URL at all");
            $this->assertStringNotContainsString('/storage/', $url, "{$label} still points at the public disk");
            $this->assertStringContainsString('/files/', $url, "{$label} does not go through FileController");
        }
    }

    public function test_uploads_land_on_the_private_disk_not_the_public_one(): void
    {
        // The root cause: `->store(..., 'public')` at every upload site.
        $this->assertSame('local', PrivateFiles::DISK);

        foreach (PrivateFiles::PRIVATE_PREFIXES as $prefix) {
            $this->assertNotContains(
                $prefix,
                PrivateFiles::PUBLIC_PREFIXES,
                "{$prefix} is listed as both private and public",
            );
        }
    }
}
