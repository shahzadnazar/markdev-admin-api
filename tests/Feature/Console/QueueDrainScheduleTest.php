<?php

namespace Tests\Feature\Console;

use App\Jobs\RecacheDeliveryScores;
use App\Models\DeliveryScoreRecord;
use App\Models\User;
use App\Services\DeliveryScoreCache;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Input\StringInput;
use Tests\Concerns\BuildsTeamPortal;
use Tests\TestCase;

/**
 * The scheduled queue drain: the thing that makes a dispatch actually happen.
 *
 * QUEUE_CONNECTION=database on a host with no worker is a silent hole. The
 * dispatch succeeds, the row lands in `jobs`, and nothing ever reads it — so
 * the settings save reports success while the recache it fired never runs. The
 * every-minute `queue:work --stop-when-empty` in routes/console.php is what
 * closes it, and these tests are here because a drain that has quietly stopped
 * working looks exactly like one that is working.
 */
class QueueDrainScheduleTest extends TestCase
{
    use BuildsTeamPortal, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeOnMonday();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** The one scheduled entry that drains the queue. */
    protected function drainEvent(): Event
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event) => Str::contains($event->command ?? '', 'queue:work'))
            ->values();

        $this->assertCount(
            1,
            $events,
            'Expected exactly one scheduled queue:work. Nothing else in routes/console.php drains the queue, '
            .'and two workers on one queue is what withoutOverlapping exists to prevent.',
        );

        return $events->first();
    }

    /** Whatever the scheduled entry passes, so these tests run the real thing. */
    protected function drainOptions(): array
    {
        $tail = Str::after($this->drainEvent()->command, 'queue:work');

        $options = [];

        foreach (preg_split('/\s+/', trim($tail), -1, PREG_SPLIT_NO_EMPTY) as $token) {
            [$name, $value] = array_pad(explode('=', $token, 2), 2, true);

            // A flag has no value; `--max-time=50` arrives quoted or not
            // depending on how Schedule compiled it.
            $options[$name] = is_string($value) ? trim($value, '\'"') : true;
        }

        return $options;
    }

    /**
     * Run the drain exactly as the scheduler would, bar one option.
     *
     * --memory is about the WORKER'S OWN PROCESS, and here there is no such
     * thing: artisan() runs the worker inside the PHPUnit process, which by the
     * time the full suite reaches this file has long passed the 128MB default.
     * The worker then quits with EXIT_MEMORY_LIMIT (12) before draining
     * anything, and it does so only in the suite -- this file passes on its own,
     * which is the worst possible shape for a test to fail in. Nothing else is
     * overridden: the flags that make the drain a drain come from the scheduled
     * entry itself.
     */
    protected function runTheDrain(): void
    {
        $this->artisan('queue:work', array_merge($this->drainOptions(), ['--memory' => 4096]))
            ->assertExitCode(0);
    }

    /* ------------------------------ The entry ------------------------------- */

    public function test_the_queue_is_drained_every_minute_without_overlapping(): void
    {
        $event = $this->drainEvent();

        $this->assertSame('* * * * *', $event->expression, 'The drain has to run on every tick of the cron entry.');
        $this->assertStringContainsString('--stop-when-empty', $event->command);
        $this->assertStringContainsString('--max-time=', $event->command);

        $this->assertTrue(
            $event->withoutOverlapping,
            'Two workers on one queue would both be live. A backlog long enough to hit --max-time is exactly '
            .'when the next tick arrives with the first still running.',
        );

        // The expiry is as load-bearing as the flag: the default mutex lasts a
        // day, so one killed worker would lock the drain out until tomorrow.
        $this->assertSame(2, $event->expiresAt, 'The overlap mutex must expire in minutes, not the default day.');
    }

    /**
     * --max-time must not outlast the tick that started it.
     *
     * Read from the command rather than hardcoded: the point is the relation to
     * the schedule, not the number.
     */
    public function test_the_worker_cannot_overrun_the_next_minute(): void
    {
        $maxTime = (int) $this->drainOptions()['--max-time'];

        $this->assertGreaterThan(0, $maxTime, '--max-time=0 is unlimited, which is the opposite of the point.');
        $this->assertLessThan(60, $maxTime, 'A worker that runs past 60 seconds is still going when the next tick fires.');
    }

    /**
     * An empty queue must cost the tick nothing.
     *
     * The worker's loop sleeps for --sleep and only THEN asks whether it should
     * stop, so --stop-when-empty on its own still idles the default three
     * seconds every minute of every day -- inside schedule:run, which runs due
     * events one after another. Zero makes the empty case, which is almost
     * every case, an immediate exit.
     */
    public function test_an_empty_queue_costs_nothing(): void
    {
        $this->assertSame(
            '0',
            $this->drainOptions()['--sleep'] ?? null,
            '--sleep must be 0: the worker sleeps before it checks --stop-when-empty.',
        );
    }

    /**
     * The compiled command has to be one the CLI will actually accept.
     *
     * This is not hypothetical. `['--stop-when-empty' => true]` is the obvious
     * way to write it and it is wrong: Schedule compiles a keyed parameter to
     * `--name=value`, that flag takes no value, and the command refuses to
     * start with "The --stop-when-empty option does not accept a value." The
     * scheduler swallows the exit code, so the only visible symptom would be
     * dispatches piling up in `jobs` forever — the exact bug this entry exists
     * to prevent, reintroduced by the entry meant to fix it.
     */
    public function test_the_scheduled_command_is_one_the_cli_will_accept(): void
    {
        $definition = Artisan::all()['queue:work']->getDefinition();

        $input = new StringInput(Str::after($this->drainEvent()->command, 'queue:work'));

        try {
            $input->bind($definition);
            $input->validate();
        } catch (\Throwable $e) {
            $this->fail('The scheduled drain would not start: '.$e->getMessage());
        }

        $this->assertTrue($input->getOption('stop-when-empty'), 'The worker must exit rather than idle out the minute.');
    }

    /* ------------------------------- The proof ------------------------------ */

    /**
     * A job dispatched on the DATABASE driver is done by the drain.
     *
     * Asserting the `jobs` table emptied would not be a proof of anything: a
     * worker that crashes on its first job also empties the table. The
     * assertion is on the work — a delivery score that did not exist before the
     * drain and does after it.
     */
    public function test_a_dispatched_recache_is_actually_done_by_the_drain(): void
    {
        $member = $this->makeMemberWithThreeOnTimeStints();

        config(['queue.default' => 'database']);

        DeliveryScoreRecord::query()->delete();

        RecacheDeliveryScores::dispatch();

        // Queued, not run: the settings save is over and nothing has happened
        // yet. This is the state the production box would have stayed in.
        $this->assertSame(1, DB::table('jobs')->count(), 'The database driver should have queued the job, not run it.');
        $this->assertSame(0, DeliveryScoreRecord::count(), 'Nothing should have been recached inside the dispatch.');

        $this->runTheDrain();

        $record = DeliveryScoreRecord::where('user_id', $member->getKey())->first();

        $this->assertNotNull($record, 'The drain ran and the recache did not. A dispatch that disappears is the bug.');
        $this->assertSame(3, $record->stints_completed);
        $this->assertSame(100, $record->percent, 'Three stints delivered on time is a full score.');
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /**
     * Why the test above asserts on the work and not on the table.
     *
     * With the cache replaced by something that throws, the drain still exits
     * cleanly and still empties `jobs` — the row moves to `failed_jobs`. Every
     * table-shaped assertion passes and nothing was recached. This test pins
     * that, so nobody later "simplifies" the proof above into one.
     */
    public function test_a_crashing_job_still_empties_the_jobs_table(): void
    {
        $this->makeMemberWithThreeOnTimeStints();

        config(['queue.default' => 'database']);

        DeliveryScoreRecord::query()->delete();

        $this->mock(DeliveryScoreCache::class)
            ->shouldReceive('refreshAll')
            ->andThrow(new RuntimeException('the recache exploded'));

        RecacheDeliveryScores::dispatch();

        $this->runTheDrain();

        $this->assertSame(0, DB::table('jobs')->count(), 'A failed job leaves the queue too.');
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(0, DeliveryScoreRecord::count(), 'Nothing was recached — which the table count alone cannot tell you.');
    }

    /** Three finished stints, because below the minimum no percentage is shown. */
    protected function makeMemberWithThreeOnTimeStints(): User
    {
        $member = $this->roleUser('team', ['name' => 'Drain Subject']);
        $team = $this->makeTeam('Web Drain', null, [$member]);

        foreach (range(1, 3) as $ignored) {
            $this->makeStint(
                $this->makeTask($team, ['days_allowed' => 5]),
                $member,
                5,
                $this->monday(),
                $this->monday()->copy()->addDays(4),
                'on_time',
            );
        }

        return $member;
    }
}
