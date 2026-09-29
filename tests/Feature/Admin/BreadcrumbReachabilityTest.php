<?php

namespace Tests\Feature\Admin;

use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\AttendanceSlot;
use App\Models\Category;
use App\Models\Client;
use App\Models\Course;
use App\Models\FeePlan;
use App\Models\HelpArticle;
use App\Models\Holiday;
use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\LessonPrivateNote;
use App\Models\Note;
use App\Models\PaymentMethod;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectStatus;
use App\Models\Quiz;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsTeamPortal;
use Tests\TestCase;

/**
 * THE THIRD LEG: no breadcrumb a role can reach refuses that role.
 *
 * SidebarSectionTest follows the sidebar's links and the topbar's forms, per
 * role. Neither follows breadcrumbs, and that is exactly how eleven team-portal
 * screens went three phases pointing "Dashboard" at `admin.dashboard` — an
 * ACADEMY route whose group refuses a team-lead and a team member. The same bug
 * as `notifications/read-all` in 41825c6, one row lower on the page: a control
 * that is offered and then refuses.
 *
 * ## Derived, not listed
 *
 * The screens come from the ROUTER — every named `admin.` GET route — and
 * reachability comes from the response: a screen that answers 403 or 404 for
 * this role is not theirs and is skipped, and every screen that renders has its
 * breadcrumbs followed. So a screen added in a later phase is covered the day it
 * appears, with nothing to add here.
 *
 * A route parameter this file cannot fill FAILS the test by name rather than
 * being skipped. A silent skip is how a derived list quietly stops being one.
 */
class BreadcrumbReachabilityTest extends TestCase
{
    use BuildsTeamPortal, RefreshDatabase;

    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        // The academy's own demo content, so courses, quizzes, invoices, help
        // articles and the rest have rows for their id-bearing screens to load.
        // Reused rather than rebuilt: it is already the fixture that exists to
        // give every admin screen real data.
        $this->seed(DemoSeeder::class);

        $this->freezeOnMonday();
        $this->seedTeamPortal();
        $this->seedTheRest();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Every role in the seeder, including the two with no panel at all. */
    public static function roles(): array
    {
        return [
            'super-admin' => ['super-admin'],
            'admin' => ['admin'],
            'manager' => ['manager'],
            'instructor' => ['instructor'],
            'team-lead' => ['team-lead'],
            'team' => ['team'],
            'client' => ['client'],
            'student' => ['student'],
        ];
    }

    /**
     * @dataProvider roles
     */
    public function test_no_breadcrumb_a_role_can_reach_refuses_them(string $role): void
    {
        $user = $this->roleUser($role, ['name' => 'Crumb '.$role]);
        // On the team, so the team-portal screens have rows to render rather
        // than 404ing and taking their breadcrumbs out of the crawl with them.
        $this->team->members()->syncWithoutDetaching([$user->id]);

        $reached = 0;
        $followed = 0;
        $refused = [];

        foreach ($this->adminScreens() as $name => $url) {
            $response = $this->actingAs($user)->get($url);

            // Not this role's screen. That is the answer the gates are for, and
            // a screen nobody can open has no breadcrumbs anybody can follow.
            // getStatusCode, not status(): a screen that streams a file answers
            // with a Symfony BinaryFileResponse, which has no status() of its
            // own — notes/{note}/download and reports/{report}/export are both
            // in this list.
            if ($response->getStatusCode() !== 200) {
                continue;
            }

            $reached++;

            foreach ($this->crumbsIn((string) $response->getContent()) as $label => $href) {
                $followed++;
                $status = $this->actingAs($user)->get($href)->getStatusCode();

                if ($status !== 200) {
                    $refused[] = sprintf('%s → "%s" (%s) answered %d', $name, $label, $href, $status);
                }
            }
        }

        $this->assertSame([], $refused, sprintf(
            "A %s is shown breadcrumbs that do not open for them:\n  %s\n\n"
            .'A crumb has to point somewhere the viewer can go. "Dashboard" for a team role means THEIR '
            .'landing screen, which App\\Support\\PortalHome already resolves — not the academy dashboard.',
            $role,
            implode("\n  ", $refused),
        ));

        if (in_array($role, ['client', 'student'], true)) {
            // No panel, so nothing to crawl. Asserted rather than assumed: if
            // these two ever DO reach an admin screen, that is its own bug and
            // this test should not be the place it hides.
            $this->assertSame(0, $reached, "a {$role} reached an admin screen");

            return;
        }

        // Asserted, not assumed: a crawl that reached nothing would make the
        // emptiness above meaningless and pass while blind.
        $this->assertGreaterThan(5, $reached, "the crawl reached almost nothing as a {$role}");
        $this->assertGreaterThan(0, $followed, "no breadcrumb was followed as a {$role}");
    }

    /* ------------------------------ The crawl ------------------------------- */

    /**
     * Every named `admin.` GET route, as name => url.
     *
     * @return array<string, string>
     */
    protected function adminScreens(): array
    {
        $screens = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! str_starts_with($name, 'admin.') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $screens[$name] = $this->urlFor($route->uri());
        }

        ksort($screens);

        return $screens;
    }

    /** Fill a route's parameters from the fixture, or fail by name. */
    protected function urlFor(string $uri): string
    {
        return '/'.preg_replace_callback('/\{(\w+)\??\}/', function (array $match) use ($uri) {
            $key = $this->fixtureKey($match[1], $uri);

            $this->assertNotNull($key, sprintf(
                'This crawl cannot fill the "%s" parameter of %s, so the screen behind it is not covered. '
                .'Add it to fixtureKey() rather than leaving a screen out — a silent skip is how a derived '
                .'list quietly stops being one.',
                $match[1],
                $uri,
            ));

            return (string) $key;
        }, $uri);
    }

    /**
     * A usable value for one route parameter.
     *
     * Keyed on the parameter NAME, not on the screen, so a new screen taking a
     * `{project}` is covered the moment it exists. The two names that mean two
     * different models are resolved by the uri they appear in.
     */
    protected function fixtureKey(string $parameter, string $uri): int|string|null
    {
        return match ($parameter) {
            'announcement' => Announcement::value('id'),
            'article' => HelpArticle::value('id'),
            'assignment' => Assignment::value('id'),
            'attendanceSlot' => AttendanceSlot::value('id'),
            'category' => Category::value('id'),
            'client' => Client::value('id'),
            'course' => Course::value('id'),
            'holiday' => Holiday::value('id'),
            'instructor' => User::role('instructor')->value('users.id'),
            'invoice' => Invoice::value('id'),
            'lesson' => Lesson::value('id'),
            'milestone' => ProjectMilestone::value('id'),
            'paymentMethod' => PaymentMethod::value('id'),
            'plan' => FeePlan::value('id'),
            'project' => Project::value('id'),
            'quiz' => Quiz::value('id'),
            // A real report key. The controller 404s an unknown one, which would
            // look like "not this role's screen" and quietly drop it.
            'report' => 'enrollments',
            'role' => Role::value('id'),
            'student' => User::role('student')->value('users.id'),
            'task' => Task::value('id'),
            'team' => Team::value('id'),
            'user' => User::role('team')->value('users.id'),
            // Two models, one parameter name, told apart by where it appears.
            'note' => str_contains($uri, 'private-notes')
                ? LessonPrivateNote::value('id')
                : Note::value('id'),
            'status' => str_contains($uri, 'project-statuses')
                ? ProjectStatus::value('id')
                : TaskStatus::value('id'),
            default => null,
        };
    }

    /**
     * The breadcrumb links on a rendered page, as label => href.
     *
     * Read out of the <nav aria-label="Breadcrumb"> block that x-page-header
     * draws, so this follows breadcrumbs and nothing else — the sidebar's links
     * and the topbar's forms are the other two legs, and they are covered in
     * SidebarSectionTest.
     *
     * @return array<string, string>
     */
    protected function crumbsIn(string $html): array
    {
        if (! preg_match('/<nav aria-label="Breadcrumb".*?<\/nav>/s', $html, $nav)) {
            return [];
        }

        preg_match_all('/<a href="([^"]+)"[^>]*>\s*(.*?)\s*<\/a>/s', $nav[0], $links, PREG_SET_ORDER);

        $crumbs = [];

        foreach ($links as [, $href, $label]) {
            $crumbs[trim(html_entity_decode(strip_tags($label)))] = html_entity_decode($href);
        }

        return $crumbs;
    }

    /* ------------------------------- Fixtures ------------------------------- */

    /** A team, a client project with a milestone, and a task on it. */
    protected function seedTeamPortal(): void
    {
        $lead = $this->roleUser('team-lead', ['name' => 'Ayesha Khan']);
        $member = $this->roleUser('team', ['name' => 'Bilal Ahmed']);

        $this->team = $this->makeTeam('Web', $lead, [$lead, $member]);
        $project = $this->makeProject($this->team);

        ProjectMilestone::create([
            'project_id' => $project->id,
            'name' => 'Design sign-off',
            'due_date' => ProjectMilestone::dayKey($this->monday()->copy()->addDays(3)),
            'sort_order' => 1,
        ]);

        $task = $this->makeTask($this->team, ['project_id' => $project->id]);
        $this->makeStint($task, $member, 3, $this->monday());
    }

    /** The handful of rows DemoSeeder does not create. */
    protected function seedTheRest(): void
    {
        AttendanceSlot::create([
            'name' => 'Morning',
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'days' => [1, 2, 3, 4, 5],
            'late_after_minutes' => 15,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Holiday::create([
            'date' => Holiday::dayKey($this->monday()->copy()->addDays(6)),
            'name' => 'Eid',
        ]);

        PaymentMethod::create([
            'name' => 'Meezan',
            'channel' => 'bank_transfer',
            'account_title' => 'MarkDev',
            'account_number' => '01234567890',
            'bank_name' => 'Meezan Bank',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $lesson = Lesson::query()->firstOrFail();
        $instructor = User::role('instructor')->firstOrFail();

        Note::create([
            'course_id' => $lesson->course_id ?? Course::value('id'),
            'instructor_id' => $instructor->id,
            'title' => 'Week one handout',
            'description' => 'The slides.',
            // A path, not a file. Nothing here downloads it; the row exists so
            // the screens that address one have an id to load.
            'file_path' => 'notes/week-one.pdf',
            'file_type' => 'pdf',
            'size_bytes' => 1024,
        ]);

        LessonPrivateNote::create([
            'lesson_id' => $lesson->id,
            'user_id' => User::role('student')->firstOrFail()->id,
            'body' => 'Stuck on the second exercise.',
        ]);
    }
}
