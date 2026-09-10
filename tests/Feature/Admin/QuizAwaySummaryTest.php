<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What the instructor is shown, and what the system must not say.
 *
 * The number is easy; the wording is the part that can do harm. Six tab
 * switches in a ten-minute quiz is a reason to look at an attempt. It is not
 * evidence of anything, because a notification, a clock check and a second
 * monitor are indistinguishable from a deliberate departure, and a phone sat
 * beside the keyboard is invisible. So the line reports and the instructor
 * concludes — never the other way round.
 */
class QuizAwaySummaryTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Quiz $quiz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');

        $course = Course::create([
            'title' => 'Course', 'slug' => 'course-'.Str::random(6), 'excerpt' => 'x',
            'level' => 'beginner', 'status' => 'published', 'published_at' => now()->subMonth(),
            'is_free' => true,
            'category_id' => Category::create(['name' => 'Web', 'slug' => 'web-'.Str::random(4)])->id,
        ]);

        $this->quiz = Quiz::create([
            'course_id' => $course->id, 'title' => 'Quiz', 'passing_score' => 50, 'is_published' => true,
        ]);
    }

    protected function attempt(int $count, int $seconds): QuizAttempt
    {
        $student = User::factory()->create(['name' => 'Ayesha Khan']);
        $student->assignRole('student');

        return QuizAttempt::create([
            'quiz_id' => $this->quiz->id, 'user_id' => $student->id,
            'started_at' => now()->subMinutes(20), 'submitted_at' => now()->subMinutes(10),
            'score' => 3, 'max_score' => 5, 'passed' => true,
            'away_count' => $count, 'away_seconds' => $seconds,
        ]);
    }

    protected function page(): string
    {
        return $this->actingAs($this->admin)
            ->get(route('admin.quizzes.attempts', $this->quiz))
            ->assertOk()
            ->getContent();
    }

    public function test_the_line_reads_as_asked(): void
    {
        $this->attempt(6, 252);

        $this->assertStringContainsString('Left the tab 6 times · 4m 12s away', $this->page());
    }

    public function test_a_single_switch_is_not_written_as_one_times(): void
    {
        $this->attempt(1, 45);

        $page = $this->page();
        $this->assertStringContainsString('Left the tab once · 45s away', $page);
        $this->assertStringNotContainsString('1 times', $page);
    }

    public function test_nothing_at_all_is_shown_when_the_count_is_zero(): void
    {
        // Including every attempt taken before this was measured, which is all
        // of them. An empty row invites reading meaning into silence.
        $this->attempt(0, 0);

        $page = $this->page();
        $this->assertStringNotContainsString('Left the tab', $page);
        $this->assertStringNotContainsString('away', strip_tags($this->rowFor($page)));
    }

    public function test_the_system_does_not_render_a_verdict(): void
    {
        $this->attempt(9, 600);
        $page = $this->page();

        // No accusation, no score, no flag.
        foreach (['cheat', 'suspicio', 'suspect', 'violation', 'integrity risk', 'flagged'] as $word) {
            $this->assertStringNotContainsString($word, mb_strtolower($page));
        }

        // And no alarm colour on the line: it wears the same muted class as
        // the email above it. A red one would be the system deciding.
        $this->assertMatchesRegularExpression(
            '/text-outline[^"]*">\s*Left the tab/',
            $page,
            'the summary must be rendered in the muted text colour, not an error one',
        );
    }

    public function test_the_page_says_what_the_signal_cannot_see(): void
    {
        // The caveat travels with the number, once, above the table — without
        // it a count can be read as evidence of something it cannot evidence.
        $this->attempt(3, 90);
        $page = mb_strtolower($this->page());

        $this->assertStringContainsString('context only', $page);
        $this->assertStringContainsString('phone', $page);
    }

    public function test_times_are_shown_in_twelve_hour_karachi(): void
    {
        $this->attempt(2, 30);

        $this->assertSame('Asia/Karachi', config('app.timezone'));
        // g:i A, like the rest of the admin — this page was on 24-hour H:i.
        $this->assertMatchesRegularExpression('/\d{1,2}:\d{2} (AM|PM)/', $this->page());
    }

    /** The markup of the one attempt row, for assertions that must be row-scoped. */
    protected function rowFor(string $page): string
    {
        $start = strpos($page, 'Ayesha Khan');

        return $start === false ? '' : substr($page, $start, 600);
    }
}
