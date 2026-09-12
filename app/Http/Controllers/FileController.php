<?php

namespace App\Http\Controllers;

use App\Models\AssignmentAttachment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LessonResource;
use App\Models\Note;
use App\Models\StudentProfile;
use App\Models\Transaction;
use App\Models\User;
use App\Support\PrivateFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way to read a private upload.
 *
 * One method per kind, because "may this user see this file" has a different
 * answer for each and a single generic check would have to be the loosest of
 * them. Each method answers its own question and then streams the bytes off
 * the private disk.
 *
 * Never a redirect. Handing back a 302 to a public URL would put the file back
 * on the open internet with an extra step, which is the thing this exists to
 * stop.
 */
class FileController extends Controller
{
    /**
     * Stream a file off the private disk, or 404.
     *
     * A row whose stored path has no file behind it is a 404 and not a 500: a
     * missing file is a data problem, not a server fault, and the viewer has
     * already been authorised by the time we get here.
     *
     * Inline for anything a browser can render (an <img> in the admin panel
     * needs it), an attachment otherwise. `false` for the disposition filename
     * would leak nothing either way, but a real name is kinder in a downloads
     * folder full of hashes.
     */
    protected function stream(?string $path, ?string $downloadAs = null): StreamedResponse
    {
        abort_if($path === null || $path === '', 404);

        $disk = Storage::disk(PrivateFiles::DISK);

        abort_unless($disk->exists($path), 404);

        $mime = $disk->mimeType($path) ?: 'application/octet-stream';
        $inline = str_starts_with($mime, 'image/') || $mime === 'application/pdf';

        return $disk->download($path, $downloadAs ?: basename($path), [
            'Content-Type' => $mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')
                .'; filename="'.addslashes($downloadAs ?: basename($path)).'"',
            // These are somebody's documents. Shared caches must not keep a
            // copy that outlives the authorisation that produced it.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /** Staff who may look at any student's record. */
    protected function isStudentStaff(User $user): bool
    {
        return $user->can('students.view');
    }

    /* ----------------------------- student documents ---------------------- */

    /**
     * A student's own photo, CNIC scan or degree certificate.
     *
     * The student themselves, or staff who may view student records. Nobody
     * else — not another student, and not an instructor, whose job needs a
     * name and a grade and never a national identity card.
     */
    public function studentDocument(Request $request, StudentProfile $profile, string $kind)
    {
        abort_unless(in_array($kind, ['photo', 'cnic', 'degree'], true), 404);

        $viewer = $request->user();

        abort_unless(
            $viewer->id === $profile->user_id || $this->isStudentStaff($viewer),
            403,
            'This document belongs to another student.',
        );

        $path = match ($kind) {
            'photo' => $profile->photo_path,
            'cnic' => $profile->cnic_doc_path,
            'degree' => $profile->degree_doc_path,
        };

        return $this->stream($path, $profile->name.' — '.$kind.'.'.pathinfo((string) $path, PATHINFO_EXTENSION));
    }

    /* --------------------------- assignment submissions ------------------- */

    /**
     * A student's submitted work.
     *
     * Its author, the instructor who has to grade it — category scoped, so an
     * instructor from another department cannot read coursework they have no
     * business marking — and admins.
     */
    public function submission(Request $request, AssignmentSubmission $submission)
    {
        $viewer = $request->user();

        abort_unless(
            $viewer->id === $submission->user_id || $this->mayGrade($viewer, $submission),
            403,
            'This submission belongs to another student.',
        );

        return $this->stream($submission->file_path);
    }

    /** Staff who may grade this submission, honouring category scoping. */
    protected function mayGrade(User $viewer, AssignmentSubmission $submission): bool
    {
        if ($viewer->hasAnyRole(['super-admin', 'admin', 'manager'])) {
            return true;
        }

        if (! $viewer->can('assignments.view')) {
            return false;
        }

        // An instructor reaches only their own courses' assignments — the same
        // rule the admin panel's lists are scoped by (77b6fd1).
        $courseId = $submission->assignment?->course_id;

        return $courseId !== null
            && Course::where('id', $courseId)->where('instructor_id', $viewer->id)->exists();
    }

    /** An assignment brief, for the students it was set for and the staff who set it. */
    public function attachment(Request $request, AssignmentAttachment $attachment)
    {
        $viewer = $request->user();
        $courseId = $attachment->assignment?->course_id;

        abort_unless(
            $this->mayReadCourseMaterial($viewer, $courseId),
            403,
            'You are not enrolled in this course.',
        );

        return $this->stream($attachment->file_path, $attachment->name);
    }

    /* -------------------------------- receipts ---------------------------- */

    /** A payment receipt: whoever paid, plus whoever may see billing. */
    public function receipt(Request $request, Transaction $transaction)
    {
        $viewer = $request->user();

        abort_unless(
            $viewer->id === $transaction->user_id || $viewer->can('billing.view'),
            403,
            'This receipt belongs to another account.',
        );

        return $this->stream($transaction->receipt_path);
    }

    /* ----------------------------- course material ------------------------ */

    /** An instructor's note file: enrolled students, and staff who may edit the course. */
    public function note(Request $request, Note $note)
    {
        abort_unless(
            $this->mayReadCourseMaterial($request->user(), $note->course_id),
            403,
            'You are not enrolled in this course.',
        );

        return $this->stream($note->file_path, $note->title);
    }

    /** A course-level or lesson-level resource file. */
    public function resource(Request $request, LessonResource $resource)
    {
        abort_unless(
            $this->mayReadCourseMaterial($request->user(), $resource->owning_course_id),
            403,
            'You are not enrolled in this course.',
        );

        return $this->stream($resource->file_path, $resource->name);
    }

    /**
     * May this person read material belonging to this course?
     *
     * An enrolled student, or staff who may edit the course — which for an
     * instructor means their own courses only, the same category scoping the
     * rest of the panel uses.
     */
    protected function mayReadCourseMaterial(User $viewer, ?int $courseId): bool
    {
        if ($courseId === null) {
            return false;
        }

        if ($viewer->hasAnyRole(['super-admin', 'admin', 'manager'])) {
            return true;
        }

        if ($viewer->can('courses.update')
            && Course::where('id', $courseId)->where('instructor_id', $viewer->id)->exists()) {
            return true;
        }

        return Enrollment::where('user_id', $viewer->id)->where('course_id', $courseId)->exists();
    }
}
