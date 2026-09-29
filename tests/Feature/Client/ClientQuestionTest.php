<?php

namespace Tests\Feature\Client;

use App\Models\Client;
use App\Models\ClientQuestion;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ClientAskedAQuestion;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsTeamPortal;
use Tests\TestCase;

/**
 * One question, one answer, and only the lead may give it.
 *
 * The rule under test is about a relationship rather than a capability: a member
 * CAN see the question — it is on a project page they can open — and must not be
 * the one who replies to the client, because the client asked the lead and the
 * lead is accountable for what the project promises. A member contributes
 * through the project discussion, which is on the same page and which the client
 * never sees.
 */
class ClientQuestionTest extends TestCase
{
    use BuildsTeamPortal, RefreshDatabase;

    protected User $admin;

    protected User $lead;

    protected User $member;

    protected User $otherLead;

    protected User $clientUser;

    protected User $otherClientUser;

    protected Project $project;

    protected Project $otherProject;

    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeOnMonday();

        $this->admin = $this->roleUser('super-admin', ['name' => 'Root Admin']);
        $this->lead = $this->roleUser('team-lead', ['name' => 'Ayesha Khan']);
        $this->member = $this->roleUser('team', ['name' => 'Bilal Ahmed']);
        $this->otherLead = $this->roleUser('team-lead', ['name' => 'Chandni Rao']);

        $this->team = $this->makeTeam('Web', $this->lead, [$this->lead, $this->member]);
        $otherTeam = $this->makeTeam('Graphics', $this->otherLead, [$this->otherLead]);

        [$this->clientUser, $this->project] = $this->makeClient('Zephyrine Quartermain', $this->team, 'PRJ-OURS');
        [$this->otherClientUser, $this->otherProject] = $this->makeClient('Crispin Fallowfield', $otherTeam, 'PRJ-THEIRS');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0: User, 1: Project} */
    protected function makeClient(string $name, Team $team, string $code): array
    {
        $user = $this->roleUser('client', ['name' => $name]);

        $client = Client::create(['name' => $name, 'user_id' => $user->id, 'is_active' => true]);
        $project = $this->makeProject($team, $client);
        $project->update(['code' => $code]);

        return [$user, $project->fresh()];
    }

    protected function ask(User $as, Project $project, string $body = 'When does the new checkout go live?')
    {
        return $this->actingAs($as)->post(route('client.questions.store', $project), ['body' => $body]);
    }

    /* -------------------------------- Asking -------------------------------- */

    public function test_a_client_asks_on_their_own_project(): void
    {
        $this->ask($this->clientUser, $this->project)->assertSessionHasNoErrors();

        $question = ClientQuestion::firstOrFail();

        $this->assertSame($this->project->id, $question->project_id);
        $this->assertSame($this->clientUser->id, $question->asked_by);
        $this->assertSame(ClientQuestion::OPEN, $question->status);
        $this->assertNull($question->answer_body);

        $this->actingAs($this->clientUser)
            ->get(route('client.projects.show', $this->project))
            ->assertOk()
            ->assertSee('When does the new checkout go live?')
            ->assertSee('Awaiting a reply');
    }

    public function test_a_client_cannot_ask_on_a_project_that_is_not_theirs(): void
    {
        $this->ask($this->clientUser, $this->otherProject)->assertNotFound();

        $this->assertSame(0, ClientQuestion::count());
    }

    public function test_an_empty_question_is_refused(): void
    {
        $this->actingAs($this->clientUser)
            ->post(route('client.questions.store', $this->project), ['body' => ''])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, ClientQuestion::count());
    }

    /**
     * A client writes once. There is no route to change or remove a question.
     *
     * Asserted on the ROUTER rather than by posting to a guessed URL: the
     * absence of the verb is the control, and a test that posted somewhere would
     * only prove that one spelling 404s.
     */
    public function test_a_client_has_no_route_to_edit_or_delete_a_question(): void
    {
        $names = collect(Route::getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter(fn (?string $name) => $name !== null && str_starts_with($name, 'client.'))
            ->values()
            ->all();

        sort($names);

        $this->assertSame([
            'client.projects.index',
            'client.projects.show',
            'client.questions.store',
        ], $names, 'The client portal grew a route. Three is the whole of it: two reads and one write.');
    }

    /* ------------------------------- Answering ------------------------------ */

    public function test_the_team_lead_answers(): void
    {
        $this->ask($this->clientUser, $this->project)->assertSessionHasNoErrors();
        $question = ClientQuestion::firstOrFail();

        $this->actingAs($this->lead)
            ->post(route('admin.projects.questions.answer', [$this->project, $question]), [
                'answer_body' => 'Friday the 12th, once the payment gateway is signed off.',
            ])
            ->assertRedirect();

        $question->refresh();

        $this->assertSame(ClientQuestion::ANSWERED, $question->status);
        $this->assertSame($this->lead->id, $question->answered_by);
        $this->assertNotNull($question->answered_at);

        // And the client reads it.
        $this->actingAs($this->clientUser)
            ->get(route('client.projects.show', $this->project))
            ->assertOk()
            ->assertSee('Friday the 12th')
            ->assertSee('Answered');
    }

    public function test_an_admin_answers(): void
    {
        $this->ask($this->clientUser, $this->project)->assertSessionHasNoErrors();
        $question = ClientQuestion::firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.projects.questions.answer', [$this->project, $question]), [
                'answer_body' => 'Answered by an administrator.',
            ])
            ->assertRedirect();

        $this->assertSame(ClientQuestion::ANSWERED, $question->fresh()->status);
    }

    /**
     * A MEMBER CANNOT ANSWER — 403, not 404.
     *
     * Nothing is being hidden: the question is on a project they can open and a
     * page they are looking at. The refusal is about who speaks to the client.
     */
    public function test_an_ordinary_team_member_cannot_answer(): void
    {
        $this->ask($this->clientUser, $this->project)->assertSessionHasNoErrors();
        $question = ClientQuestion::firstOrFail();

        $this->actingAs($this->member)
            ->post(route('admin.projects.questions.answer', [$this->project, $question]), [
                'answer_body' => 'Going behind the lead.',
            ])
            ->assertForbidden();

        $this->assertSame(ClientQuestion::OPEN, $question->fresh()->status);
        $this->assertNull($question->fresh()->answer_body);
    }

    /** And the form is not even drawn for them, so it is not a control that refuses. */
    public function test_the_answer_box_is_drawn_for_the_lead_and_not_for_a_member(): void
    {
        $this->ask($this->clientUser, $this->project)->assertSessionHasNoErrors();
        $question = ClientQuestion::firstOrFail();

        $action = route('admin.projects.questions.answer', [$this->project, $question]);

        $this->actingAs($this->lead)
            ->get(route('admin.projects.show', $this->project))
            ->assertOk()
            ->assertSee($action, escape: false);

        $memberHtml = $this->actingAs($this->member)
            ->get(route('admin.projects.show', $this->project))
            ->assertOk()
            ->getContent();

        // They see the question — it is their project — and no answer box.
        $this->assertStringContainsString('When does the new checkout go live?', $memberHtml);
        $this->assertStringNotContainsString($action, $memberHtml);
    }

    /** A lead on another team is outside the work entirely: 404, not 403. */
    public function test_a_lead_on_another_team_gets_a_404(): void
    {
        $this->ask($this->clientUser, $this->project)->assertSessionHasNoErrors();
        $question = ClientQuestion::firstOrFail();

        $this->actingAs($this->otherLead)
            ->post(route('admin.projects.questions.answer', [$this->project, $question]), [
                'answer_body' => 'Not my project.',
            ])
            ->assertNotFound();
    }

    /** A question id from another project reached through this project's URL. */
    public function test_a_question_from_another_project_is_a_404(): void
    {
        $this->ask($this->otherClientUser, $this->otherProject)->assertSessionHasNoErrors();
        $theirs = ClientQuestion::firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.projects.questions.answer', [$this->project, $theirs]), [
                'answer_body' => 'Posted onto the wrong engagement.',
            ])
            ->assertNotFound();

        $this->assertSame(ClientQuestion::OPEN, $theirs->fresh()->status);
    }

    public function test_a_question_is_answered_once(): void
    {
        $this->ask($this->clientUser, $this->project)->assertSessionHasNoErrors();
        $question = ClientQuestion::firstOrFail();

        $answer = fn (string $body) => $this->actingAs($this->lead)
            ->post(route('admin.projects.questions.answer', [$this->project, $question]), ['answer_body' => $body]);

        $answer('The first answer.')->assertRedirect();
        $answer('A second, different answer.')->assertRedirect();

        $this->assertSame('The first answer.', $question->fresh()->answer_body);
    }

    public function test_a_question_can_be_closed_without_an_answer(): void
    {
        $this->ask($this->clientUser, $this->project)->assertSessionHasNoErrors();
        $question = ClientQuestion::firstOrFail();

        $this->actingAs($this->lead)
            ->post(route('admin.projects.questions.close', [$this->project, $question]))
            ->assertRedirect();

        $this->assertSame(ClientQuestion::CLOSED, $question->fresh()->status);

        // The client is told so, rather than left waiting.
        $this->actingAs($this->clientUser)
            ->get(route('client.projects.show', $this->project))
            ->assertOk()
            ->assertSee('Closed without an answer');
    }

    public function test_a_member_cannot_close_either(): void
    {
        $this->ask($this->clientUser, $this->project)->assertSessionHasNoErrors();
        $question = ClientQuestion::firstOrFail();

        $this->actingAs($this->member)
            ->post(route('admin.projects.questions.close', [$this->project, $question]))
            ->assertForbidden();

        $this->assertSame(ClientQuestion::OPEN, $question->fresh()->status);
    }

    /* ----------------------------- The eighth bell -------------------------- */

    public function test_asking_notifies_the_team_lead_and_nobody_else(): void
    {
        $this->ask($this->clientUser, $this->project)->assertSessionHasNoErrors();

        $this->assertSame(1, $this->lead->notifications()->where('type', ClientAskedAQuestion::class)->count());

        foreach ([$this->member, $this->admin, $this->otherLead] as $notThem) {
            $this->assertSame(0, $notThem->notifications()->count(), $notThem->name);
        }

        $data = (array) $this->lead->notifications()->firstOrFail()->data;

        $this->assertSame('A client asked a question', $data['title']);
        $this->assertStringContainsString('PRJ-OURS', $data['message']);
        $this->assertStringStartsWith('/admin/projects/', $data['action_url']);
    }

    /** The client is told nothing, by any of the eight events. */
    public function test_the_client_gets_no_notification_for_their_own_question_or_its_answer(): void
    {
        $this->ask($this->clientUser, $this->project)->assertSessionHasNoErrors();
        $question = ClientQuestion::firstOrFail();

        $this->actingAs($this->lead)
            ->post(route('admin.projects.questions.answer', [$this->project, $question]), ['answer_body' => 'Friday.'])
            ->assertRedirect();

        $this->assertSame(0, $this->clientUser->notifications()->count());
    }

    /** A project with no lead produces no notification and no error. */
    public function test_a_project_whose_team_has_no_lead_still_accepts_a_question(): void
    {
        $this->team->update(['team_lead_id' => null]);

        $this->ask($this->clientUser, $this->project)->assertSessionHasNoErrors();

        $this->assertSame(1, ClientQuestion::count());
    }
}
