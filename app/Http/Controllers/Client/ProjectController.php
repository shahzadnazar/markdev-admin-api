<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ClientQuestion;
use App\Models\Project;
use App\Notifications\ClientAskedAQuestion;
use App\Support\ClientPortal;
use App\Support\PortalNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * What a client sees of their own work.
 *
 * ## NOT the admin panel
 *
 * A separate namespace, a separate route group and a separate layout. Every
 * /admin view assumes a panel user — the sidebar, the topbar bell, the
 * breadcrumb that resolves to a panel screen, the `@can` gates — and sharing one
 * with a client would mean growing an "unless they are a client" branch in each,
 * which is exactly where the leak would be. Nothing here renders an admin view,
 * partial or component; what IS shared (the card, the page header, the badge) are
 * components that receive only what they display and know nothing about who is
 * looking.
 *
 * ## Every read is scoped from the session
 *
 * Through App\Support\ClientPortal, which starts from `clients.user_id === the
 * signed-in user`. No client id ever arrives from the request. A project outside
 * their own is a 404, not a 403 — a refusal would confirm that somebody else's
 * engagement exists.
 *
 * ## What is not here
 *
 * No team, no member, no task, no comment, no stint, no score, no contract value
 * and no currency. Those are not gated out of the view — they are not loaded, and
 * the milestone and file queries filter on `is_client_visible` so an unflagged
 * row is absent rather than merely unrendered.
 */
class ProjectController extends Controller
{
    /**
     * The client's project list.
     *
     * Plain counts of their questions beside each project, which is how a client
     * learns an answer has arrived. NOT an unread count: "answered since you last
     * looked" needs a per-client read marker, and a read marker is the first
     * piece of a notification system — which this portal deliberately does not
     * have. A client has no bell and no notification rows of any kind.
     */
    public function index(Request $request): View
    {
        $projects = ClientPortal::projects($request->user())
            ->with('status:id,label,colour')
            ->withCount([
                'questions as answered_questions_count' => fn ($query) => $query->where('status', ClientQuestion::ANSWERED),
                'questions as open_questions_count' => fn ($query) => $query->where('status', ClientQuestion::OPEN),
            ])
            ->ordered()
            // Named column by column: no client_id, no contract_value, no
            // currency, no team_id. The select list is a better place for that
            // promise than a view that has to remember it.
            ->get(['id', 'name', 'code', 'project_status_id', 'start_date', 'due_date', 'description']);

        return view('client.projects.index', [
            'client' => ClientPortal::clientFor($request->user()),
            'projects' => $projects,
        ]);
    }

    /**
     * One project: its dates, the milestones and files marked for them, and
     * their questions.
     */
    public function show(Request $request, Project $project): View
    {
        // 404, not 403 — see the class docblock. Asked before anything is loaded.
        abort_unless(ClientPortal::seesProject($request->user(), $project), 404);

        $project->load('status:id,label,colour');

        return view('client.projects.show', [
            'client' => ClientPortal::clientFor($request->user()),
            'project' => $project,
            'milestones' => ClientPortal::milestones($project),
            'files' => ClientPortal::files($project),
            'questions' => ClientPortal::questions($project),
        ]);
    }

    /**
     * Ask a question about this project.
     *
     * The one thing a client may write, and the only write route in this group.
     * There is no update and no destroy: a question asked is asked — see the
     * model.
     *
     * The notice to the team-lead is sent inside the transaction, on the same
     * connection, so a question that rolls back takes its notification with it.
     */
    public function ask(Request $request, Project $project)
    {
        abort_unless(ClientPortal::seesProject($request->user(), $project), 404);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ], [
            'body.required' => 'Type your question first.',
        ]);

        DB::transaction(function () use ($project, $request, $data) {
            $question = ClientQuestion::create([
                'project_id' => $project->getKey(),
                'asked_by' => $request->user()->getKey(),
                'body' => trim($data['body']),
            ]);

            // The eighth notification, and the only one a client causes. It goes
            // to the project's TEAM-LEAD, who is the person the client asked —
            // not to the team, and not to every admin. Through PortalNotifier
            // like the other seven, so the lead's own visibility is what decides
            // whether it is written, and a project with no lead simply produces
            // nothing rather than an error.
            PortalNotifier::notify(
                $project->team?->lead,
                $project,
                new ClientAskedAQuestion($question),
            );
        });

        return back()->with('success', 'Your question has been sent to the team.');
    }
}
