<?php

namespace Tests\Feature\Admin;

use App\Exports\TeamAttendanceExport;
use App\Exports\TeamFineLedgerExport;
use App\Exports\TeamMemberDeliveryExport;
use App\Exports\TeamProjectDeliveryExport;
use App\Models\Client;
use App\Models\Project;
use App\Models\TaskAssignment;
use App\Models\Team;
use App\Models\TeamAbsenceFine;
use App\Models\TeamAttendance;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\Concerns\BuildsTeamPortal;
use Tests\TestCase;

/**
 * AN EXPORT BYPASSES EVERY VIEW GATE.
 *
 * A report is generated from a query, not from a screen, so not one `@can`
 * protecting the dashboard applies to it. That is why almost everything here
 * asserts on the GENERATED ROWS rather than on the HTTP status of a download:
 * the status is 200 whether the spreadsheet holds a lead's own teams or every
 * team in the company, and only the rows can tell the difference.
 *
 * The seeded strings are distinctive — "Quinces Ravensworth", "Marmalade
 * Graphics", "987654.32" — so "absent from every column" means what it says.
 */
class TeamReportTest extends TestCase
{
    use BuildsTeamPortal, RefreshDatabase;

    protected User $admin;

    protected User $lead;

    protected User $member;

    protected User $otherLead;

    protected Team $team;

    protected Team $otherTeam;

    protected Project $project;

    protected Project $otherProject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeOnMonday();

        $this->admin = $this->roleUser('super-admin', ['name' => 'Root Admin']);
        $this->lead = $this->roleUser('team-lead', ['name' => 'Ayesha Khan']);
        $this->member = $this->roleUser('team', ['name' => 'Bilal Ahmed']);
        $this->otherLead = $this->roleUser('team-lead', ['name' => 'Quinces Ravensworth']);

        $this->team = $this->makeTeam('Peregrine Web Squad', $this->lead, [$this->lead, $this->member]);
        $this->otherTeam = $this->makeTeam('Marmalade Graphics', $this->otherLead, [$this->otherLead]);

        $client = Client::create(['name' => 'Zephyrine Quartermain', 'company' => 'Bartleby Ironworks PLC', 'is_active' => true]);

        $this->project = $this->makeProject($this->team, $client);
        $this->project->update(['code' => 'PRJ-OURS', 'name' => 'Peregrine Rebuild']);

        $this->otherProject = $this->makeProject($this->otherTeam, $client);
        $this->otherProject->update(['code' => 'PRJ-THEIRS', 'name' => 'Marmalade Rebrand']);

        $this->seedWork();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** A finished stint, a register row and a fine, on each team. */
    protected function seedWork(): void
    {
        foreach ([[$this->team, $this->project, $this->member], [$this->otherTeam, $this->otherProject, $this->otherLead]] as [$team, $project, $person]) {
            $task = $this->makeTask($team, ['project_id' => $project->id, 'title' => 'Work on '.$project->code, 'days_allowed' => 4]);
            $this->makeStint($task, $person, 4, $this->monday(), $this->monday()->copy()->addDays(2), 'on_time');

            TeamAttendance::create([
                'user_id' => $person->id,
                'date' => TeamAttendance::dayKey($this->monday()),
                'status' => 'present',
                'source' => 'manual',
                'marked_by' => $this->admin->id,
                'marked_at' => now(),
            ]);

            TeamAbsenceFine::create([
                'user_id' => $person->id,
                'month' => TeamAbsenceFine::dayKey($this->monday()->copy()->startOfMonth()),
                'allowance' => 2,
                'absences' => 5,
                'chargeable' => 3,
                'rate' => 500,
                'total' => 1500,
            ]);
        }
    }

    /** Every cell of every row, flattened, as strings. */
    protected function cells(Collection $rows): string
    {
        return $rows->flatten()->map(fn ($cell) => (string) $cell)->implode(' | ');
    }

    /* ---------------------------- Who may export ---------------------------- */

    public function test_a_team_member_cannot_export_anything(): void
    {
        $this->assertFalse($this->member->can('team-reports.view'));
        $this->assertFalse($this->member->can('team-reports.export'));

        $this->actingAs($this->member)->get(route('admin.team-reports.index'))->assertForbidden();

        foreach (['member-delivery', 'project-delivery', 'team-attendance', 'fine-ledger'] as $report) {
            $this->actingAs($this->member)
                ->get(route('admin.team-reports.export', $report))
                ->assertForbidden("a member exported {$report}");
        }
    }

    public function test_a_client_and_the_academy_roles_cannot_export_anything(): void
    {
        foreach (['client', 'student', 'manager', 'instructor'] as $role) {
            $viewer = $this->roleUser($role);

            $this->actingAs($viewer)->get(route('admin.team-reports.index'))->assertForbidden();
            $this->actingAs($viewer)
                ->get(route('admin.team-reports.export', 'member-delivery'))
                ->assertForbidden("a {$role} exported member-delivery");
        }
    }

    /** THE FINE LEDGER IS ADMIN ONLY — a lead never sees a colleague's money. */
    public function test_a_lead_cannot_export_fines_at_all(): void
    {
        $this->actingAs($this->lead)
            ->get(route('admin.team-reports.export', 'fine-ledger'))
            ->assertForbidden();

        // Nor is it offered: a control that refuses is the bug this build has
        // spent three phases removing.
        $this->actingAs($this->lead)
            ->get(route('admin.team-reports.index'))
            ->assertOk()
            ->assertSee('Member delivery')
            ->assertSee('Team attendance')
            ->assertDontSee('Absence fine ledger');

        $this->actingAs($this->admin)
            ->get(route('admin.team-reports.index'))
            ->assertOk()
            ->assertSee('Absence fine ledger');

        $this->actingAs($this->admin)
            ->get(route('admin.team-reports.export', 'fine-ledger'))
            ->assertOk();
    }

    /* --------------------------- What the rows hold ------------------------- */

    /**
     * A lead's delivery export contains no row belonging to another team.
     *
     * Asserted on the ROWS. The download is 200 either way.
     */
    public function test_a_lead_exporting_delivery_gets_only_their_teams_rows(): void
    {
        $rows = (new TeamMemberDeliveryExport($this->lead, $this->monday()))->rows();

        $this->assertStringContainsString('Bilal Ahmed', $this->cells($rows));
        $this->assertStringNotContainsString('Quinces Ravensworth', $this->cells($rows));

        // The other lead's own export is the mirror image, so this is not
        // passing because one side has nothing.
        $theirs = (new TeamMemberDeliveryExport($this->otherLead, $this->monday()))->rows();
        $this->assertStringContainsString('Quinces Ravensworth', $this->cells($theirs));
        $this->assertStringNotContainsString('Bilal Ahmed', $this->cells($theirs));

        // An admin gets both.
        $all = (new TeamMemberDeliveryExport($this->admin, $this->monday()))->rows();
        $this->assertStringContainsString('Bilal Ahmed', $this->cells($all));
        $this->assertStringContainsString('Quinces Ravensworth', $this->cells($all));
    }

    public function test_a_lead_exporting_projects_gets_only_their_teams_projects(): void
    {
        $rows = (new TeamProjectDeliveryExport($this->lead))->rows();

        $this->assertStringContainsString('PRJ-OURS', $this->cells($rows));
        $this->assertStringNotContainsString('PRJ-THEIRS', $this->cells($rows));

        $this->assertStringContainsString('PRJ-THEIRS', $this->cells((new TeamProjectDeliveryExport($this->admin))->rows()));
    }

    public function test_a_lead_exporting_attendance_gets_only_their_teams_register(): void
    {
        $rows = (new TeamAttendanceExport($this->lead, $this->monday()))->rows();

        $this->assertStringContainsString('Bilal Ahmed', $this->cells($rows));
        $this->assertStringNotContainsString('Quinces Ravensworth', $this->cells($rows));
    }

    /**
     * NO MONEY IN ANY COLUMN OF ANY EXPORT A LEAD CAN PRODUCE.
     *
     * Every cell of every row of all three, checked against the contract value,
     * the client and the fine figures — not against the headings, because a
     * leak would be in the data.
     */
    public function test_no_money_and_no_client_appears_in_any_export_a_lead_can_produce(): void
    {
        $exports = [
            'member-delivery' => (new TeamMemberDeliveryExport($this->lead, $this->monday()))->rows(),
            'project-delivery' => (new TeamProjectDeliveryExport($this->lead))->rows(),
            'team-attendance' => (new TeamAttendanceExport($this->lead, $this->monday()))->rows(),
        ];

        foreach ($exports as $name => $rows) {
            $cells = $this->cells($rows);

            foreach (['987654', '987,654', 'Bartleby Ironworks', 'Zephyrine Quartermain', '1500', '1,500', 'PKR'] as $needle) {
                $this->assertStringNotContainsString($needle, $cells, "\"{$needle}\" is in a lead's {$name} export");
            }
        }

        // And the rows are not empty, or the absences above prove nothing.
        $this->assertGreaterThan(0, $exports['member-delivery']->count());
        $this->assertGreaterThan(0, $exports['project-delivery']->count());
        $this->assertGreaterThan(0, $exports['team-attendance']->count());
    }

    /** The headings and the rows are the same width, in all four. */
    public function test_every_export_row_matches_its_headings(): void
    {
        $exports = [
            new TeamMemberDeliveryExport($this->admin, $this->monday()),
            new TeamProjectDeliveryExport($this->admin),
            new TeamAttendanceExport($this->admin, $this->monday()),
            new TeamFineLedgerExport($this->admin, $this->monday()),
        ];

        foreach ($exports as $export) {
            $rows = $export->rows();

            $this->assertGreaterThan(0, $rows->count(), $export::class.' produced nothing to check');

            foreach ($rows as $row) {
                $this->assertCount(count($export->headings()), $row, $export::class.' row is the wrong width');
            }
        }
    }

    /* ------------------------------- The figures ---------------------------- */

    public function test_project_delivery_reports_days_promised_against_days_taken(): void
    {
        $row = (new TeamProjectDeliveryExport($this->lead))->rows()->firstWhere(1, 'PRJ-OURS');

        $this->assertSame('Peregrine Rebuild', $row[0]);
        $this->assertSame(1, $row[3]);       // one task
        $this->assertSame(4, $row[4]);       // four days promised
        $this->assertIsInt($row[5]);         // days taken
        $this->assertSame($row[5] - $row[4], $row[6]); // the variance is signed
    }

    public function test_member_delivery_counts_the_stints_finished_in_the_month(): void
    {
        $row = (new TeamMemberDeliveryExport($this->lead, $this->monday()))->rows()->first();

        $this->assertSame('Bilal Ahmed', $row[0]);
        $this->assertSame(1, $row[2]);   // stints finished
        $this->assertSame(0, $row[3]);   // early
        $this->assertSame(1, $row[4]);   // on time
        $this->assertSame(0, $row[5]);   // late
        $this->assertSame(4, $row[6]);   // days promised

        // A month with nothing finished in it is empty, not last month's rows.
        $this->assertCount(0, (new TeamMemberDeliveryExport($this->lead, $this->monday()->copy()->subMonth()))->rows());
    }

    /**
     * EARLY AND ON TIME ARE SEPARATE COLUMNS, so a scoreboard built from the
     * spreadsheet can rank two people the percentage cannot.
     *
     * The delivery score caps at 100, so somebody early on every stint and
     * somebody on time on every stint read the same number. 2427004 fixed that
     * on the screen by drawing the early count; this file had the same defect
     * one layer out, with both outcomes added into a single "On time or early".
     *
     * Asserted on the GENERATED ROWS, because the spreadsheet is the artefact
     * somebody ranks from — a heading alone proves nothing about what is in the
     * cells under it.
     */
    public function test_an_early_member_and_an_on_time_member_are_distinguishable(): void
    {
        // Two more people on the lead's own team, so the scoping stays intact:
        // one early on everything, one on time on everything, same allowances.
        $earlyBird = $this->roleUser('team', ['name' => 'Aabid Early']);
        $steady = $this->roleUser('team', ['name' => 'Aabir Steady']);
        $this->team->members()->syncWithoutDetaching([$earlyBird->id, $steady->id]);

        foreach ([[$earlyBird, 'early'], [$steady, 'on_time']] as [$person, $outcome]) {
            foreach ([1, 2] as $n) {
                $task = $this->makeTask($this->team, [
                    'project_id' => $this->project->id,
                    'title' => "Task {$n} for {$person->name}",
                    'days_allowed' => 4,
                ]);

                $this->makeStint($task, $person, 4, $this->monday(), $this->monday()->copy()->addDay(), $outcome);
            }
        }

        $rows = (new TeamMemberDeliveryExport($this->lead, $this->monday()))->rows()->keyBy(0);

        $early = $rows['Aabid Early'];
        $onTime = $rows['Aabir Steady'];

        // Indistinguishable by every figure the old single column carried…
        $this->assertSame($early[2], $onTime[2], 'both finished the same number of stints');
        $this->assertSame($early[6], $onTime[6], 'both were promised the same days');
        $this->assertSame(0, $early[5]);
        $this->assertSame(0, $onTime[5]);

        // …and told apart by the two columns that used to be one.
        $this->assertSame([2, 0], [$early[3], $early[4]], 'the early member should read 2 early, 0 on time');
        $this->assertSame([0, 2], [$onTime[3], $onTime[4]], 'the on-time member should read 0 early, 2 on time');

        // AND THE SCOPING IS UNTOUCHED. Both are on this lead's team, so neither
        // reaches the other lead's spreadsheet — the split changed what a row
        // says, not whose rows they are.
        $theirs = $this->cells((new TeamMemberDeliveryExport($this->otherLead, $this->monday()))->rows());
        $this->assertStringNotContainsString('Aabid Early', $theirs);
        $this->assertStringNotContainsString('Aabir Steady', $theirs);
    }

    /**
     * The three finished outcomes add up to the total.
     *
     * TaskAssignment::FINISHED is exactly early, on_time and late, so a row where
     * they do not sum means an outcome was added to that constant and not to this
     * export — which would show up as a column quietly missing work rather than
     * as an error.
     */
    public function test_the_outcome_columns_sum_to_the_stints_finished(): void
    {
        $this->assertSame(['early', 'on_time', 'late'], TaskAssignment::FINISHED);

        $late = $this->makeTask($this->team, ['project_id' => $this->project->id, 'days_allowed' => 2]);
        $this->makeStint($late, $this->member, 2, $this->monday(), $this->monday()->copy()->addDays(5), 'late');

        $rows = (new TeamMemberDeliveryExport($this->admin, $this->monday()))->rows();

        $this->assertGreaterThan(0, $rows->count());

        foreach ($rows as $row) {
            $this->assertSame(
                $row[2],
                $row[3] + $row[4] + $row[5],
                "early + on time + late does not equal the stints finished for {$row[0]}",
            );
        }
    }

    public function test_the_fine_ledger_holds_the_month_asked_for(): void
    {
        $rows = (new TeamFineLedgerExport($this->admin, $this->monday()))->rows();

        $this->assertCount(2, $rows);
        $this->assertStringContainsString('1500', $this->cells($rows));

        $this->assertCount(0, (new TeamFineLedgerExport($this->admin, $this->monday()->copy()->subMonth()))->rows());
    }

    /** An unknown report is a 404; a known one they may not have is a 403. */
    public function test_an_unknown_report_is_a_404(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.team-reports.export', 'not-a-report'))
            ->assertNotFound();
    }
}
