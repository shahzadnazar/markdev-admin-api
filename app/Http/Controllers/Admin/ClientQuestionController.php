<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientQuestion;
use App\Models\Project;
use App\Support\TeamWorkVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The team's side of a client question: one answer, or a close.
 *
 * ## Two refusals, two status codes, and the difference is the point
 *
 * A question on a project OUTSIDE your teams is a 404 — the same rule every
 * other team-portal read follows, because a refusal would confirm that somebody
 * else's client engagement exists.
 *
 * A question on a project you CAN see, that you are not the lead of, is a 403.
 * Nothing is being hidden: it is on a page you are looking at, and pretending it
 * does not exist would only be confusing. The refusal is about who may speak to
 * the client, not about what you may know.
 *
 * ## Why an ordinary member may not answer
 *
 * The client asked the lead. A member replying directly is the team talking to
 * the client without the lead knowing — and the lead is the person accountable
 * for what this project promises. A member contributes through the project
 * discussion, which is right there on the same page and which the client cannot
 * see.
 *
 * There is no second comment surface here and there must not be one: the
 * discussion of a question happens in the discussion.
 */
class ClientQuestionController extends Controller
{
    public function answer(Request $request, Project $project, ClientQuestion $question): RedirectResponse
    {
        $this->assertReachable($request, $project, $question);
        $this->assertMayAnswer($request, $question);

        if (! $question->isOpen()) {
            return back()->with('error', 'That question has already been dealt with.');
        }

        $data = $request->validate([
            'answer_body' => ['required', 'string', 'max:4000'],
        ], [
            'answer_body.required' => 'Write the answer the client will read.',
        ]);

        $question->update([
            'answer_body' => trim($data['answer_body']),
            'status' => ClientQuestion::ANSWERED,
            'answered_by' => $request->user()->getKey(),
            'answered_at' => now(),
        ]);

        // Nothing is sent to the client. They have no bell and no notification
        // rows; they see it marked answered next time they open the project.
        return back()->with('success', 'Answer sent to the client.');
    }

    /**
     * Close a question no answer is coming for.
     *
     * Asked twice, withdrawn, or dealt with on a call. Said plainly rather than
     * left open for ever, because a question that sits "awaiting a reply"
     * indefinitely is how a client concludes nobody read it. Same authority as
     * answering: it is still the lead speaking to the client, just briefly.
     */
    public function close(Request $request, Project $project, ClientQuestion $question): RedirectResponse
    {
        $this->assertReachable($request, $project, $question);
        $this->assertMayAnswer($request, $question);

        if (! $question->isOpen()) {
            return back()->with('error', 'That question has already been dealt with.');
        }

        $question->update([
            'status' => ClientQuestion::CLOSED,
            'answered_by' => $request->user()->getKey(),
            'answered_at' => now(),
        ]);

        return back()->with('success', 'Question closed without an answer.');
    }

    /* ------------------------------- Guards -------------------------------- */

    /** The project is yours to see, and the question is on THAT project. */
    protected function assertReachable(Request $request, Project $project, ClientQuestion $question): void
    {
        abort_unless(TeamWorkVisibility::seesProject($request->user(), $project), 404);

        // A question id from another project reached through this project's URL
        // is a 404, not an answer posted onto somebody else's engagement.
        abort_unless((int) $question->project_id === (int) $project->getKey(), 404);
    }

    protected function assertMayAnswer(Request $request, ClientQuestion $question): void
    {
        abort_unless(
            $question->mayBeAnsweredBy($request->user()),
            403,
            'Only this project\'s team lead answers the client. Raise it in the project discussion instead.',
        );
    }
}
