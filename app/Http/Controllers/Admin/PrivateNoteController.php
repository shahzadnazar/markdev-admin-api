<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LessonPrivateNote;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * A super-admin's read path into student private notes.
 *
 * HOW NARROWLY THIS IS OPENED, since the absence of any such path was the
 * deliberate design in cb384ae:
 *
 *  - Two routes. Both GET. There is no update, no delete and no form, so the
 *    application cannot modify a note on anyone's behalf. Read only is not a
 *    policy here, it is an absence of verbs.
 *  - Gated on `private-notes.read`, which is a ROLE check on super-admin
 *    rather than a permission in the matrix, so it cannot be granted to an
 *    admin, a manager or an instructor. See AppServiceProvider.
 *  - The index lists WHO keeps notes and WHERE — student, lesson, when last
 *    written — and never the body. Nothing is read there, so nothing is
 *    logged there.
 *  - Opening ONE note is what reads content, and that writes an audit row
 *    naming the super-admin, the student and the lesson. One request, one
 *    note, one row: an index that returned bodies would have made a hundred
 *    reads look like one.
 */
class PrivateNoteController extends Controller
{
    /** Who has written notes, and where. Bodies are not selected. */
    public function index(Request $request): View
    {
        Gate::authorize('private-notes.read');

        $notes = LessonPrivateNote::query()
            // The body column is deliberately not in this list. A note's
            // content is read on its own page, where it can be audited.
            ->select(['id', 'lesson_id', 'user_id', 'updated_at'])
            ->with(['user:id,name,email', 'lesson:id,title,course_id', 'lesson.course:id,title'])
            ->latest('updated_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.private-notes.index', ['notes' => $notes]);
    }

    /** One student's note on one lesson. Reading it is logged. */
    public function show(Request $request, LessonPrivateNote $note): View
    {
        Gate::authorize('private-notes.read');

        $note->load(['user:id,name,email', 'lesson:id,title,course_id', 'lesson.course:id,title']);

        // Logged BEFORE the view renders, so a failure further down cannot
        // leave a read unrecorded. The body is not in the payload: the point
        // of the row is that the note was opened, and copying its contents
        // into the audit trail would spread what it is meant to be protecting.
        AuditLogger::log(
            'viewed',
            'private_notes',
            $note->id,
            null,
            [
                'student_id' => $note->user_id,
                'student_name' => $note->user?->name,
                'lesson_id' => $note->lesson_id,
                'lesson_title' => $note->lesson?->title,
                'course_title' => $note->lesson?->course?->title,
            ],
        );

        return view('admin.private-notes.show', ['note' => $note]);
    }
}
