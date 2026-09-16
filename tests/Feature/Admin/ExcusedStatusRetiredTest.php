<?php

namespace Tests\Feature\Admin;

use App\Models\DailyAttendance;
use App\Models\Setting;
use App\Models\User;
use App\Support\AttendanceWeights;
use App\Support\RuleBook;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Concerns\BuildsSettingsPayload;
use Tests\TestCase;

/**
 * `excused` is gone, and everything that counted statuses still adds up.
 *
 * It arrived with the retired class-attendance sheet (bfc3d89) because that
 * table had the word, not because this project wanted a fifth status. Its
 * source table was dropped in 2026_09_10_090200 and its rows became `present`
 * in 2026_09_16_160000.
 *
 * `present` and not `leave`, because `leave` asserts an approved application
 * exists and LeaveAllowance spends the student's monthly quota against it;
 * relabelling would charge students for days they never applied for. And not
 * `absent`, which locks behind the admin-only correction rule and is billable.
 */
class ExcusedStatusRetiredTest extends TestCase
{
    use BuildsSettingsPayload, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    protected function mark(User $student, string $date, string $status): DailyAttendance
    {
        return DailyAttendance::create([
            'user_id' => $student->id, 'date' => $date,
            'status' => $status, 'source' => 'manual', 'marked_at' => now(),
        ]);
    }

    /* ------------------------------ the vocabulary --------------------------- */

    public function test_the_register_knows_four_statuses(): void
    {
        $this->assertSame(['present', 'late', 'absent', 'leave'], DailyAttendance::STATUSES);
        $this->assertNotContains('excused', DailyAttendance::STATUSES);
        $this->assertArrayNotHasKey('excused', DailyAttendance::WEIGHTS);
        $this->assertArrayNotHasKey('excused', AttendanceWeights::all());
        $this->assertCount(4, AttendanceWeights::all());
    }

    /**
     * No live code path mentions the word.
     *
     * Comments are excluded: the model, the conversion migration and the
     * backfill all explain the retirement in prose, and a guard that fires on
     * its own explanation is a guard people delete.
     */
    public function test_no_code_path_references_excused(): void
    {
        $roots = [app_path(), base_path('resources'), base_path('routes')];
        $offences = [];

        foreach ($roots as $root) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
                if (! in_array($file->getExtension(), ['php', 'blade'], true)) {
                    continue;
                }

                $code = preg_replace(
                    ['#/\*[\s\S]*?\*/#', '#\{\{--[\s\S]*?--\}\}#', '#^\s*//.*$#m'],
                    ' ',
                    file_get_contents($file->getPathname()),
                );

                if (stripos($code, 'excused') !== false) {
                    $offences[] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }

        sort($offences);

        $this->assertSame(
            [],
            $offences,
            "`excused` is retired — these still reference it in code:\n  ".implode("\n  ", $offences),
        );
    }

    /* ------------------------------ the conversion --------------------------- */

    public function test_a_converted_row_scores_100_where_it_scored_50(): void
    {
        // What the migration did, re-run on a row it would have caught.
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->mark($student, '2026-06-01', 'present');
        DB::table('daily_attendance_records')->insert([
            'user_id' => $student->id, 'date' => '2026-06-02', 'status' => 'excused',
            'source' => 'manual', 'marked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Before: the row is not one of the four, so it reaches no percentage.
        $this->assertSame(
            100.0,
            DailyAttendance::weightedPercent(
                DailyAttendance::where('user_id', $student->id)->counted()->get()->countBy('status')->all(),
            ),
        );

        DB::table('daily_attendance_records')->where('status', 'excused')->update(['status' => 'present']);

        // After: two present days, both counted.
        $counts = DailyAttendance::where('user_id', $student->id)->counted()->get()->countBy('status')->all();
        $this->assertSame(['present' => 2], $counts);
        $this->assertSame(100.0, DailyAttendance::weightedPercent($counts));
    }

    /**
     * An excused day was worth 50 and a present day is worth 100, so an
     * affected student's percentage RISES. Pinned with the arithmetic spelled
     * out, because it is the one user-visible consequence of this change.
     */
    public function test_the_rise_is_exactly_the_difference_between_50_and_100(): void
    {
        $counts = ['present' => 6, 'late' => 2, 'absent' => 1, 'excused' => 3];
        $before = DailyAttendance::weightedPercent(
            array_intersect_key($counts, ['present' => 1, 'late' => 1, 'absent' => 1])
                + ['excused' => 3],
        );

        // weightedPercent walks AttendanceWeights, so an unknown status now
        // contributes nothing AND counts no days — the same as before the
        // conversion, when it contributed 50 each.
        $after = DailyAttendance::weightedPercent(['present' => 9, 'late' => 2, 'absent' => 1]);

        // 6 present + 2 late + 1 absent = (600 + 140 + 0) / 9 = 82.2
        $this->assertSame(82.2, $before);
        // 9 present + 2 late + 1 absent = (900 + 140 + 0) / 12 = 86.7
        $this->assertSame(86.7, $after);
        $this->assertGreaterThan($before, $after);
    }

    public function test_an_unaffected_students_percentage_is_identical(): void
    {
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->mark($student, '2026-06-01', 'present');
        $this->mark($student, '2026-06-02', 'late');
        $this->mark($student, '2026-06-03', 'absent');
        $this->mark($student, '2026-06-04', 'leave');

        $percent = fn () => DailyAttendance::weightedPercent(
            DailyAttendance::where('user_id', $student->id)->counted()->get()->countBy('status')->all(),
        );

        // (100 + 70 + 0 + 50) / 4 = 55.0, before and after the conversion runs.
        $before = $percent();
        DB::table('daily_attendance_records')->where('status', 'excused')->update(['status' => 'present']);

        $this->assertSame(55.0, $before);
        $this->assertSame($before, $percent(), 'a student with no excused rows must not move at all');
    }

    /* -------------------------------- settings ------------------------------- */

    public function test_the_settings_page_shows_four_weight_fields(): void
    {
        $page = $this->actingAs($this->admin)->get(route('admin.settings.edit'))->assertOk();

        foreach (['present', 'late', 'leave', 'absent'] as $status) {
            $page->assertSee('attendance_weight_'.$status, false);
        }

        $page->assertDontSee('attendance_weight_excused', false);
        $page->assertViewHas('settings', fn (array $s) => count($s['attendance_weights']) === 4);
    }

    public function test_the_settings_form_still_saves_without_the_excused_field(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), $this->settingsPayload(['attendance_weight_late' => 80]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        Setting::forgetCached();

        $this->assertSame(80, AttendanceWeights::for('late'));
        $this->assertCount(4, AttendanceWeights::all());
    }

    /* --------------------------- things that add up -------------------------- */

    public function test_the_status_cards_still_sum_to_total_sessions(): void
    {
        $student = User::factory()->create(['is_active' => true]);
        $student->assignRole('student');

        $this->mark($student, today()->toDateString(), 'present');

        $counts = $this->actingAs($this->admin)
            ->get(route('admin.attendance.daily', ['date' => today()->toDateString()]))
            ->assertOk()
            ->viewData('counts');

        $byStatus = array_sum(array_map(
            fn (string $status) => (int) ($counts[$status] ?? 0),
            DailyAttendance::STATUSES,
        ));

        $this->assertSame(
            $byStatus + (int) ($counts['unmarked'] ?? 0),
            (int) $counts['total'],
            'the four status cards plus unmarked have to account for every student',
        );
    }

    public function test_the_rules_page_weight_table_has_four_rows(): void
    {
        $table = RuleBook::weightTable();

        $this->assertCount(4, $table);
        $this->assertSame(
            ['present', 'late', 'leave', 'absent'],
            array_column($table, 'status'),
        );
        $this->assertNotContains('Excused', array_column($table, 'label'));
    }

    public function test_the_rules_page_worked_example_recomputes_from_the_live_weights(): void
    {
        // 9 present + 1 late at the defaults: (900 + 70) / 10 = 97.
        $this->assertSame('9 days present + 1 day late over 10 days = 97%.', RuleBook::weightedExample());

        Setting::updateOrCreate(['key' => 'attendance_weight_late'], ['value' => 80, 'group' => 'general']);
        Setting::forgetCached();

        // (900 + 80) / 10 = 98 — the example follows the setting, with four
        // statuses exactly as it did with five.
        $this->assertSame('9 days present + 1 day late over 10 days = 98%.', RuleBook::weightedExample());
    }

    public function test_progress_still_resolves_attendance_for_a_student_with_data(): void
    {
        // Progress reads attendance through weightedPercent (bc636c7); four
        // statuses must not change that.
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->mark($student, today()->subDays(2)->toDateString(), 'present');
        $this->mark($student, today()->subDay()->toDateString(), 'late');

        $counts = DailyAttendance::where('user_id', $student->id)
            ->counted()->get()->countBy('status')->all();

        // (100 + 70) / 2 = 85.0
        $this->assertSame(85.0, DailyAttendance::weightedPercent($counts));
    }
}
