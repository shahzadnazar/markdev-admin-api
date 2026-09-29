<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ManagesTeamComments;
use App\Http\Controllers\Controller;
use App\Models\TeamChannelMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The one cross-team channel.
 *
 * Every team-portal user reads and posts here — it is the surface with no
 * project or task behind it, which is the point: somebody on Web needs a place
 * to ask somebody on Graphics a question. Clients, instructors, managers and
 * students reach none of it; the route's own gate is `tasks.view`, which only
 * the team roles and the two admin roles hold.
 *
 * ANNOUNCEMENTS are admin-only and land here rather than in a second place,
 * because a second place is a place people stop reading. They are pinned above
 * the conversation and drawn distinctly.
 */
class TeamChannelController extends Controller
{
    use ManagesTeamComments;

    public function index(Request $request): View
    {
        return view('admin.team-channel.index', [
            'threads' => TeamChannelMessage::query()
                ->threads()
                ->with(['author:id,name', 'replies.author:id,name', 'mentions.user:id,name'])
                ->pinnedFirst()
                ->get(),
            'mayAnnounce' => $request->user()->can('clients.view'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedComment($request, 'team_channel_messages');

        $announcement = $request->boolean('is_announcement');

        // Checked here rather than left to the route, because it is a property
        // of the MESSAGE and not of the endpoint: the same form posts both.
        if ($announcement && ! $request->user()->can('clients.view')) {
            throw ValidationException::withMessages([
                'is_announcement' => 'Only an administrator can post an announcement.',
            ]);
        }

        // An announcement is a top-level statement. A pinned reply would be a
        // thing pinned above the thread it belongs to.
        if ($announcement && $data['parent_id'] !== null) {
            throw ValidationException::withMessages([
                'is_announcement' => 'An announcement starts its own thread.',
            ]);
        }

        TeamChannelMessage::create($data + [
            'user_id' => $request->user()->id,
            'is_announcement' => $announcement,
        ]);

        return back()->with('success', $announcement ? 'Announcement posted.' : 'Posted.');
    }

    public function update(Request $request, TeamChannelMessage $message): RedirectResponse
    {
        $this->assertMayEdit($request->user(), $message);

        $message->update(['body' => trim((string) $request->input('body'))]);

        return back()->with('success', 'Updated.');
    }

    public function destroy(Request $request, TeamChannelMessage $message): RedirectResponse
    {
        $this->assertMayDelete($request->user(), $message);

        $message->delete();

        return back()->with('success', 'Deleted.');
    }
}
