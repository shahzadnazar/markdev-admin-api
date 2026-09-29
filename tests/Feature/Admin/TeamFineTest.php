<?php

namespace Tests\Feature\Admin;

use App\Models\AbsenceFineCharge;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\TeamAbsenceFine;
use App\Models\TeamAttendance;
use App\Models\User;
use App\Support\TeamFineRules;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTeamPortal;
use Tests\TestCase;

/**
 * The absence ledger: the team's own rate, and who may see a figure at all.
 */
class TeamFineTest extends TestCase
{
    use BuildsTeamPortal, RefreshDatabase;

    protected User $admin;

    protected User $lead;

    protected User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeOnMonday();

        $this->admin = $this->roleUser('super-admin');
        $this->lead = $this->roleUser('team-lead', ['name' => 'Lead Person']);
        $this->member = $this->roleUser('team', ['name' => 'Member Person']);
        $this->makeTeam('Web', $this->lead, [$this->lead, $this->member]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Absences on working days in the anchor month. */
    protected function absences(User $user, int $days): void
    {
        $day = $this->monday()->copy();
        $written = 0;

        while ($written < $days) {
            if ($day->isWeekday()) {
                TeamAttendance::create([
                    'user_id' => $user->id,
                    'date' => TeamAttendance::dayKey($day),
                    'status' => 'absent',
                ]);
                $written++;
            }

            $day->addDay();
        }
    }

    protected function setTeamRules(int $allowance, float $rate): void
    {
        Setting::updateOrCreate(['key' => TeamFineRules::ALLOWANCE_KEY], ['value' => $allowance, 'group' => 'general']);
        Setting::updateOrCreate(['key' => TeamFineRules::RATE_KEY], ['value' => $rate, 'group' => 'general']);
        Setting::forgetCached();
    }

    /* ------------------------------ The arithmetic -------------------------- */

    public function test_a_member_over_the_team_allowance_is_charged_the_team_rate(): void
    {
        $this->setTeamRules(2, 750);
        $this->absences($this->member, 5);

        $this->artisan('attendance:charge-absent-fines', ['--month' => $this->monday()->toDateString()])
            ->assertSuccessful();

        $fine = TeamAbsenceFine::where('user_id', $this->member->id)->firstOrFail();

        $this->assertSame(5, $fine->absences);
        $this->assertSame(2, $fine->allowance);
        $this->assertSame(3, $fine->chargeable);
        $this->assertSame('750.00', (string) $fine->rate);
        $this->assertSame('2250.00', (string) $fine->total);
    }

    /**
     * Changing a student number must not move a team figure.
     *
     * The two populations share nothing but the calendar, and this is the
     * direction that would be easiest to get wrong: the academy's fine settings
     * are older and their names are the ones already in everybody's head.
     */
    public function test_changing_the_student_rate_does_not_move_the_team_figure(): void
    {
        $this->setTeamRules(2, 750);
        $this->absences($this->member, 5);

        Setting::updateOrCreate(['key' => 'absent_fine_amount'], ['value' => 99999, 'group' => 'general']);
        Setting::updateOrCreate(['key' => 'monthly_absent_allowance'], ['value' => 30, 'group' => 'general']);
        Setting::forgetCached();

        $this->artisan('attendance:charge-absent-fines', ['--month' => $this->monday()->toDateString()])
            ->assertSuccessful();

        $fine = TeamAbsenceFine::where('user_id', $this->member->id)->firstOrFail();

        $this->assertSame('750.00', (string) $fine->rate);
        $this->assertSame('2250.00', (string) $fine->total);
    }

    public function test_a_month_within_the_allowance_still_gets_a_row(): void
    {
        $this->setTeamRules(2, 750);
        $this->absences($this->member, 1);

        $this->artisan('attendance:charge-absent-fines', ['--month' => $this->monday()->toDateString()])
            ->assertSuccessful();

        // "Settled at zero" and "never looked at" have to be different, or a
        // later correction has no baseline.
        $fine = TeamAbsenceFine::where('user_id', $this->member->id)->firstOrFail();
        $this->assertSame(0, $fine->chargeable);
        $this->assertSame('0.00', (string) $fine->total);
    }

    public function test_the_command_is_safe_to_re_run(): void
    {
        $this->setTeamRules(2, 750);
        $this->absences($this->member, 5);

        $this->artisan('attendance:charge-absent-fines', ['--month' => $this->monday()->toDateString()]);
        $this->artisan('attendance:charge-absent-fines', ['--month' => $this->monday()->toDateString()]);

        $this->assertSame(1, TeamAbsenceFine::where('user_id', $this->member->id)->count());
    }

    public function test_the_row_snapshots_the_rules(): void
    {
        $this->setTeamRules(2, 750);
        $this->absences($this->member, 5);

        $this->artisan('attendance:charge-absent-fines', ['--month' => $this->monday()->toDateString()]);

        // Raising the rate next quarter must not rewrite last quarter's ledger
        // under the person who already paid it.
        $this->setTeamRules(1, 2000);

        $fine = TeamAbsenceFine::where('user_id', $this->member->id)->firstOrFail();
        $this->assertSame('750.00', (string) $fine->rate);
        $this->assertSame(2, $fine->allowance);
    }

    public function test_no_invoice_is_created_for_a_team_fine(): void
    {
        $this->setTeamRules(2, 750);
        $this->absences($this->member, 5);

        $this->artisan('attendance:charge-absent-fines', ['--month' => $this->monday()->toDateString()]);

        // A ledger, not a bill. AbsenceFineCharge is coupled to invoices, which
        // is why it was not extended.
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, AbsenceFineCharge::count());
    }

    /* ------------------------------- Who may look --------------------------- */

    public function test_a_team_lead_gets_a_404_on_another_members_ledger(): void
    {
        $this->setTeamRules(2, 750);
        $this->absences($this->member, 5);
        $this->artisan('attendance:charge-absent-fines', ['--month' => $this->monday()->toDateString()]);

        // 404, not 403: a refusal would tell the lead this person has a ledger
        // worth refusing them, which is the fact being protected.
        $this->actingAs($this->lead)
            ->get(route('admin.team-fines.show', $this->member))
            ->assertNotFound();

        $this->actingAs($this->lead)->get(route('admin.team-fines.index'))->assertForbidden();
    }

    public function test_a_member_sees_their_own_ledger_and_an_admin_sees_everyones(): void
    {
        $this->setTeamRules(2, 750);
        $this->absences($this->member, 5);
        $this->artisan('attendance:charge-absent-fines', ['--month' => $this->monday()->toDateString()]);

        $this->actingAs($this->member)->get(route('admin.team-fines.mine'))
            ->assertOk()->assertSee('2,250.00');

        $this->actingAs($this->admin)->get(route('admin.team-fines.show', $this->member))
            ->assertOk()->assertSee('2,250.00');

        $this->actingAs($this->admin)->get(route('admin.team-fines.index'))
            ->assertOk()->assertSee('Member Person');
    }

    /**
     * No fine figure anywhere a lead can reach.
     *
     * Not just the ledger screens — every team-portal page a lead opens is
     * checked for the number, because the leak this guards against would most
     * likely arrive as a well-meaning summary on some other screen.
     */
    public function test_no_fine_figure_renders_on_any_page_a_lead_can_reach(): void
    {
        $this->setTeamRules(2, 750);
        $this->absences($this->member, 5);
        $this->artisan('attendance:charge-absent-fines', ['--month' => $this->monday()->toDateString()]);

        foreach ([
            'admin.tasks.index',
            'admin.tasks.board',
            'admin.teams.index',
            'admin.projects.index',
            'admin.team-attendance.index',
            'admin.team-attendance.mine',
            'admin.team-leave.mine',
        ] as $route) {
            $response = $this->actingAs($this->lead)->get(route($route))->assertOk();

            $response->assertDontSee('2,250.00', false);
            $response->assertDontSee('2250.00', false);
        }
    }

    public function test_only_an_admin_may_settle(): void
    {
        $this->setTeamRules(2, 750);
        $this->absences($this->member, 5);
        $this->artisan('attendance:charge-absent-fines', ['--month' => $this->monday()->toDateString()]);

        $fine = TeamAbsenceFine::firstOrFail();

        $this->actingAs($this->member)->post(route('admin.team-fines.settle', $fine))->assertForbidden();
        $this->actingAs($this->lead)->post(route('admin.team-fines.settle', $fine))->assertForbidden();

        $this->actingAs($this->admin)->post(route('admin.team-fines.settle', $fine), ['notes' => 'Paid in cash'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($fine->fresh()->isSettled());
        $this->assertSame($this->admin->id, $fine->fresh()->settled_by);
    }
}
