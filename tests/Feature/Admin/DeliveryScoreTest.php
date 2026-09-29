<?php

namespace Tests\Feature\Admin;

use App\Models\DeliveryScoreRecord;
use App\Models\Setting;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Services\DeliveryScoreCache;
use App\Services\DeliveryScoreCalculator;
use App\Support\DeliveryScore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTeamPortal;
use Tests\TestCase;

/**
 * The delivery score: day-weighted, never task-counted, and never bare.
 */
class DeliveryScoreTest extends TestCase
{
    use BuildsTeamPortal, RefreshDatabase;

    protected DeliveryScoreCalculator $calculator;

    protected User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeOnMonday();

        $this->calculator = app(DeliveryScoreCalculator::class);
        $this->member = $this->roleUser('team', ['name' => 'Member Person']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** A finished stint of a stated shape, with no clock arithmetic involved. */
    protected function stint(int $days, string $outcome): TaskAssignment
    {
        $team = $this->makeTeam('Web '.uniqid(), null, [$this->member]);
        $task = $this->makeTask($team, ['days_allowed' => $days]);

        return $this->makeStint(
            $task,
            $this->member,
            $days,
            $this->monday(),
            $this->monday()->copy()->addDays(4),
            $outcome,
        );
    }

    protected function setMinimum(int $stints): void
    {
        Setting::updateOrCreate(['key' => DeliveryScore::MINIMUM_KEY], ['value' => $stints, 'group' => 'general']);
        Setting::forgetCached();
    }

    /* ----------------------------- Day weighting ---------------------------- */

    /**
     * Ten one-day stints on time do not wash out one ten-day stint that was late.
     *
     * Counting stints would read 91%. Counting days reads 50%, which is the
     * true picture: half the days this person was trusted with came in late.
     */
    public function test_ten_short_on_time_stints_do_not_outscore_one_long_late_one(): void
    {
        $this->setMinimum(1);

        for ($i = 0; $i < 10; $i++) {
            $this->stint(1, 'on_time');
        }

        $this->stint(10, 'late');

        $score = $this->calculator->for($this->member);

        $this->assertSame(50, $score['percent']);
        $this->assertSame(11, $score['stints_completed']);
        $this->assertSame(1, $score['late_count']);
    }

    public function test_a_clean_record_is_a_hundred(): void
    {
        $this->setMinimum(1);

        $this->stint(3, 'on_time');
        $this->stint(2, 'early');

        $this->assertSame(100, $this->calculator->for($this->member)['percent']);
    }

    /* ------------------------------- Handover ------------------------------- */

    public function test_a_handed_over_stint_is_in_neither_half_of_the_score(): void
    {
        $this->setMinimum(1);

        $this->stint(4, 'on_time');
        $this->stint(6, 'handed_over');

        $score = $this->calculator->for($this->member);

        // 4/4, not 4/10 and not 10/10. The leaver did not finish, so they
        // cannot be marked on time; they were not given the chance to be late.
        $this->assertSame(100, $score['percent']);
        $this->assertSame(1, $score['stints_completed']);
    }

    public function test_a_handover_alone_leaves_somebody_unscored(): void
    {
        $this->setMinimum(1);

        $this->stint(6, 'handed_over');

        $score = $this->calculator->for($this->member);

        $this->assertNull($score['percent']);
        $this->assertSame(0, $score['stints_completed']);
    }

    /* ------------------------------- Minimum -------------------------------- */

    public function test_below_the_minimum_there_is_no_percentage_at_all(): void
    {
        $this->setMinimum(3);

        $this->stint(2, 'late');

        $score = $this->calculator->for($this->member);

        // Null, not 0. One finished task must not decide whether somebody
        // reads 0% or 100%, and "no answer yet" has to stay a different
        // statement from "a bad answer".
        $this->assertNull($score['percent']);
        $this->assertSame(1, $score['stints_completed']);
        $this->assertSame(3, $score['minimum']);
    }

    public function test_reaching_the_minimum_reveals_the_percentage(): void
    {
        $this->setMinimum(3);

        $this->stint(1, 'on_time');
        $this->stint(1, 'on_time');
        $this->assertNull($this->calculator->for($this->member)['percent']);

        $this->stint(1, 'late');
        $this->assertSame(67, $this->calculator->for($this->member)['percent']);
    }

    /* ------------------------------- Early mode ----------------------------- */

    public function test_early_counts_the_same_as_on_time_by_default(): void
    {
        $this->setMinimum(1);

        $this->stint(4, 'early');
        $this->stint(4, 'late');

        $this->assertSame('same', DeliveryScore::earlyMode());
        $this->assertSame(50, $this->calculator->for($this->member)['percent']);
    }

    public function test_early_can_be_made_worth_more(): void
    {
        $this->setMinimum(1);
        Setting::updateOrCreate(['key' => DeliveryScore::EARLY_MODE_KEY], ['value' => 'better', 'group' => 'general']);
        Setting::forgetCached();

        $this->stint(4, 'early');
        $this->stint(4, 'late');

        // 4 × 1.25 out of 8 — the bonus lifts a mixed record rather than
        // inventing a score above full marks, which the cap guarantees.
        $this->assertSame(63, $this->calculator->for($this->member)['percent']);
    }

    public function test_the_bonus_never_pushes_a_score_past_a_hundred(): void
    {
        $this->setMinimum(1);
        Setting::updateOrCreate(['key' => DeliveryScore::EARLY_MODE_KEY], ['value' => 'better', 'group' => 'general']);
        Setting::forgetCached();

        $this->stint(5, 'early');

        $this->assertSame(100, $this->calculator->for($this->member)['percent']);
    }

    /* ------------------------------- The cache ------------------------------ */

    public function test_one_persons_screen_computes_live_and_a_list_reads_the_cache(): void
    {
        $this->setMinimum(1);
        $this->stint(3, 'on_time');

        $cache = app(DeliveryScoreCache::class);
        $cache->refresh($this->member);

        $row = DeliveryScoreRecord::where('user_id', $this->member->id)->firstOrFail();

        $this->assertSame(100, $row->percent);
        $this->assertSame(1, $row->stints_completed);

        // The cached row and the live calculation agree. Two surfaces
        // disagreeing about the same person is the bug this arrangement is
        // designed around.
        $this->assertSame(
            $this->calculator->for($this->member)['percent'],
            $row->percent,
        );

        // And a list screen costs ONE query for the figures, however many
        // people it lists — which is the only reason the cache exists.
        $second = $this->roleUser('team');
        $cache->refresh($second);

        DB::enableQueryLog();
        DeliveryScoreRecord::whereIn('user_id', [$this->member->id, $second->id])->get();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries);
    }

    public function test_the_live_calculation_does_not_grow_with_the_number_of_stints(): void
    {
        $this->setMinimum(1);

        for ($i = 0; $i < 6; $i++) {
            $this->stint(2, 'on_time');
        }

        // Leave in the fixture, deliberately: without it the leave source costs
        // nothing to measure and this assertion would pass while blind to
        // exactly what it is here to watch. It is a join rather than an eager
        // load for the same reason — `with` would be a second query the moment
        // there was any leave to load.
        $this->approveLeaveFor($this->member, $this->monday(), $this->monday()->copy()->addDays(2));

        DB::enableQueryLog();
        $this->calculator->for($this->member);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Eager-loaded, so six stints cost the same as one. A query per stint
        // is what the progress-percent work was written to avoid, and this is
        // the same arrangement.
        fwrite(STDERR, "\nQUERIES: {$queries}\n");
        $this->assertLessThanOrEqual(8, $queries, 'the live score is running a query per stint');
    }

    /* ----------------------------- The component ---------------------------- */

    /**
     * The score is never rendered as a bare number.
     *
     * Not "should not" — cannot: there is one component, and it draws the
     * counts whether or not there is a percentage to draw them beside.
     */
    public function test_the_component_never_renders_a_percentage_without_its_counts(): void
    {
        $this->setMinimum(1);
        $this->stint(4, 'on_time');
        $this->stint(4, 'late');

        $html = Blade::render(
            '<x-team.delivery-score :score="$score" />',
            ['score' => $this->calculator->for($this->member)],
        );

        $this->assertStringContainsString('50%', $html);

        foreach (['completed', 'late', 'days over', 'blocked'] as $context) {
            $this->assertStringContainsString($context, $html);
        }
    }

    public function test_the_component_says_so_when_there_is_not_enough_work(): void
    {
        $this->setMinimum(5);
        $this->stint(4, 'on_time');

        $html = Blade::render(
            '<x-team.delivery-score :score="$score" />',
            ['score' => $this->calculator->for($this->member)],
        );

        $this->assertStringContainsString('Not enough completed work yet', $html);
        $this->assertStringNotContainsString('%', strip_tags($html));
        // Still with the counts: "1 of 5 finished stints" is the useful part.
        $this->assertStringContainsString('1 of 5 finished stints', $html);
    }
}
