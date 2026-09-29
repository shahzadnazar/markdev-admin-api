<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\TeamFile;
use App\Support\PrivateFiles;
use App\Support\TeamWorkVisibility;
use App\Support\UploadLimits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Files on a project or a task.
 *
 * ## The private disk, always
 *
 * Bytes go to PrivateFiles::DISK under `team-files/` — outside the document
 * root, unreachable by any URL — and come back only through FileController,
 * which asks whether this viewer can see the owning project or task. That was
 * settled the day a student's photograph turned out to be readable by anyone
 * who guessed the path.
 *
 * ## Who may do what
 *
 *   upload           anybody who can see the work
 *   delete           the uploader, or an admin
 *   client-visible   an ADMIN ONLY — a lead and a member may upload and may not
 *                    decide what a client sees, which matches milestones, whose
 *                    routes are already admin-only, so the two answers agree
 *
 * ## Limits
 *
 * Through UploadLimits, which takes the smaller of the rule and what php.ini
 * will really accept. The numbers live on TeamFile; nothing here restates them.
 */
class TeamFileController extends Controller
{
    public function store(Request $request, string $ownerType, int $ownerId): RedirectResponse
    {
        $owner = $this->owner($ownerType, $ownerId);

        abort_unless(TeamWorkVisibility::seesOwner($request->user(), $owner), 404);

        $request->validate([
            'file' => [
                'required', 'file',
                // An image is held to the tighter ceiling; anything else to the
                // looser one. `max` is in kilobytes, which is what the
                // constants are.
                'max:'.TeamFile::FILE_MAX_KB,
            ],
        ]);

        $upload = $request->file('file');

        if (str_starts_with((string) $upload->getMimeType(), 'image/')
            && $upload->getSize() > TeamFile::IMAGE_MAX_KB * 1024) {
            return back()->withErrors([
                'file' => 'Images are limited to '.UploadLimits::maxLabel(TeamFile::IMAGE_MAX_KB).'.',
            ]);
        }

        $path = $upload->store(TeamFile::PREFIX, PrivateFiles::DISK);

        $owner->files()->create([
            'path' => $path,
            'original_name' => $upload->getClientOriginalName(),
            'mime' => $upload->getMimeType(),
            'size_bytes' => $upload->getSize(),
            'uploaded_by' => $request->user()->id,
            // Never on upload. Only an admin decides, and only afterwards.
            'is_client_visible' => false,
        ]);

        return back()->with('success', 'File uploaded.');
    }

    /**
     * Soft delete. The BYTES STAY.
     *
     * A restored row whose file is gone is worse than one that takes disk
     * space: the first is a broken record somebody has to explain, the second
     * is a line item on a bill.
     */
    public function destroy(Request $request, TeamFile $file): RedirectResponse
    {
        abort_unless(TeamWorkVisibility::seesOwner($request->user(), $file->owner), 404);
        abort_unless($file->mayBeDeletedBy($request->user()), 403);

        $file->delete();

        return back()->with('success', 'File removed.');
    }

    /** ADMIN ONLY — the route says so too, and this says why. */
    public function visibility(Request $request, TeamFile $file): RedirectResponse
    {
        abort_unless(TeamWorkVisibility::seesOwner($request->user(), $file->owner), 404);

        $data = $request->validate([
            'is_client_visible' => ['required', Rule::in(['0', '1'])],
        ]);

        $file->update(['is_client_visible' => $data['is_client_visible'] === '1']);

        return back()->with('success', $file->is_client_visible
            ? "\"{$file->original_name}\" will be shown to the client."
            : "\"{$file->original_name}\" is internal again.");
    }

    /** Only a project or a task owns files, and an unknown type is not found. */
    protected function owner(string $ownerType, int $ownerId): Model
    {
        $model = match ($ownerType) {
            'project' => Project::find($ownerId),
            'task' => Task::find($ownerId),
            default => null,
        };

        abort_if($model === null, 404);

        return $model;
    }
}
