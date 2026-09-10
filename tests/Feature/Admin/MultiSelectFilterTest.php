<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The list filters take several values at once.
 *
 * `?category=3` became `?category[]=3&category[]=5`, which moves every
 * affected filter from `where` to `whereIn` and raises three questions the
 * shared concern answers once: an old single-value link must still work,
 * ticking nothing must mean no filter rather than no rows, and a value the
 * dropdown never offered must never reach the query.
 *
 * That last one is not only validation. The options are the allowed set, and
 * an instructor's options are already narrowed to what they teach — so it is
 * also what stops a hand-typed id widening their view.
 */
class MultiSelectFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function userWith(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    protected function category(string $name): Category
    {
        return Category::create(['name' => $name, 'slug' => Str::slug($name.'-'.Str::random(4))]);
    }

    protected function course(string $title, Category $category, array $extra = []): Course
    {
        return Course::create(array_merge([
            'title' => $title,
            'slug' => Str::slug($title.'-'.Str::random(4)),
            'excerpt' => 'x',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'is_free' => true,
            'category_id' => $category->id,
        ], $extra));
    }

    /* -------------------------------- Courses ------------------------------- */

    public function test_two_categories_ticked_shows_both_and_nothing_else(): void
    {
        $web = $this->category('Web');
        $design = $this->category('Design');
        $data = $this->category('Data');
        $this->course('Laravel', $web);
        $this->course('Figma', $design);
        $this->course('Pandas', $data);

        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.courses.index', ['category' => [$web->id, $design->id]]))
            ->assertOk()
            ->assertSee('Laravel', false)
            ->assertSee('Figma', false)
            ->assertDontSee('Pandas', false);
    }

    public function test_ticking_everything_and_ticking_nothing_both_show_the_full_list(): void
    {
        $web = $this->category('Web');
        $design = $this->category('Design');
        $this->course('Laravel', $web);
        $this->course('Figma', $design);

        $admin = $this->userWith('admin');

        $all = $this->actingAs($admin)
            ->get(route('admin.courses.index', ['category' => [$web->id, $design->id]]))->assertOk();
        $none = $this->actingAs($admin)
            ->get(route('admin.courses.index', ['category' => []]))->assertOk();
        $absent = $this->actingAs($admin)->get(route('admin.courses.index'))->assertOk();

        foreach ([$all, $none, $absent] as $response) {
            $response->assertSee('Laravel', false)->assertSee('Figma', false);
        }
    }

    public function test_an_old_single_value_url_still_filters(): void
    {
        $web = $this->category('Web');
        $this->course('Laravel', $web);
        $this->course('Figma', $this->category('Design'));

        // The shape a bookmark or a pasted link carries.
        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.courses.index').'?category='.$web->id)
            ->assertOk()
            ->assertSee('Laravel', false)
            ->assertDontSee('Figma', false);
    }

    public function test_an_unknown_value_in_the_array_is_dropped_and_the_rest_still_filter(): void
    {
        $web = $this->category('Web');
        $this->course('Laravel', $web);
        $this->course('Figma', $this->category('Design'));

        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.courses.index', ['category' => [$web->id, 999999]]))
            ->assertOk()
            ->assertSee('Laravel', false)
            ->assertDontSee('Figma', false);
    }

    public function test_a_filter_of_only_unknown_values_filters_nothing_rather_than_everything(): void
    {
        $this->course('Laravel', $this->category('Web'));

        // Dropped, not refused — and what is left is an empty selection, which
        // is no filter. A stale link must not read as an empty academy.
        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.courses.index', ['category' => [999999]]))
            ->assertOk()
            ->assertSee('Laravel', false);
    }

    public function test_a_nested_array_cannot_reach_the_query(): void
    {
        $this->course('Laravel', $this->category('Web'));

        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.courses.index').'?category[][]=1')
            ->assertOk()
            ->assertSee('Laravel', false);
    }

    public function test_level_and_status_tick_several_too(): void
    {
        $web = $this->category('Web');
        $this->course('Beginner course', $web, ['level' => 'beginner', 'status' => 'published']);
        $this->course('Advanced course', $web, ['level' => 'advanced', 'status' => 'draft', 'published_at' => null]);
        $this->course('Middle course', $web, ['level' => 'intermediate', 'status' => 'archived']);

        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.courses.index', ['level' => ['beginner', 'advanced']]))
            ->assertOk()
            ->assertSee('Beginner course', false)
            ->assertSee('Advanced course', false)
            ->assertDontSee('Middle course', false);

        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.courses.index', ['status' => ['draft', 'archived']]))
            ->assertOk()
            ->assertSee('Advanced course', false)
            ->assertSee('Middle course', false)
            ->assertDontSee('Beginner course', false);
    }

    /* ------------------------ Scoping: the options leak --------------------- */

    /**
     * An instructor's category dropdown used to list every category in the
     * academy, naming fields they do not teach. It is narrowed to the
     * categories their own courses sit in — which is also the set the filter
     * will accept, so the two cannot disagree.
     */
    public function test_an_instructor_only_sees_their_own_categories_as_options(): void
    {
        $mine = $this->category('My Field');
        $theirs = $this->category('Somebody Elses Field');
        $instructor = $this->userWith('instructor');
        $this->course('Mine', $mine, ['instructor_id' => $instructor->id]);
        $this->course('Theirs', $theirs);

        $this->actingAs($instructor)->get(route('admin.courses.index'))->assertOk()
            ->assertSee('My Field', false)
            ->assertDontSee('Somebody Elses Field', false);
    }

    public function test_an_instructor_cannot_filter_by_a_category_they_cannot_see(): void
    {
        $mine = $this->category('My Field');
        $theirs = $this->category('Their Field');
        $instructor = $this->userWith('instructor');
        $this->course('Mine', $mine, ['instructor_id' => $instructor->id]);
        $this->course('Theirs', $theirs);

        // The id is dropped, leaving an empty selection — so they get their own
        // list, never a widened one.
        $this->actingAs($instructor)
            ->get(route('admin.courses.index', ['category' => [$theirs->id]]))
            ->assertOk()
            ->assertSee('Mine', false)
            ->assertDontSee('Theirs', false);
    }

    public function test_an_admin_still_sees_every_category_as_an_option(): void
    {
        $this->category('Web');
        $this->category('Design');

        $this->actingAs($this->userWith('admin'))->get(route('admin.courses.index'))->assertOk()
            ->assertSee('Web', false)
            ->assertSee('Design', false);
    }

    /* --------------------------------- Users -------------------------------- */

    public function test_two_roles_ticked_shows_both(): void
    {
        $manager = User::factory()->create(['name' => 'Mira Manager']);
        $manager->assignRole('manager');
        $instructor = User::factory()->create(['name' => 'Ivan Instructor']);
        $instructor->assignRole('instructor');
        // A third party, not the viewer: the topbar shows whoever is signed in,
        // so asserting their absence from the page would fail on the chrome
        // rather than on the filter.
        $other = User::factory()->create(['name' => 'Ada Admin']);
        $other->assignRole('admin');

        $this->actingAs($this->userWith('super-admin'))
            ->get(route('admin.users.index', ['role' => ['manager', 'instructor']]))
            ->assertOk()
            ->assertSee('Mira Manager', false)
            ->assertSee('Ivan Instructor', false)
            ->assertDontSee('Ada Admin', false);
    }

    public function test_a_role_the_dropdown_does_not_offer_is_dropped(): void
    {
        $manager = $this->userWith('manager');

        // `student` is excluded from the options because students have their
        // own module — so it cannot be asked for either.
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.users.index', ['role' => ['student']]))
            ->assertOk()
            ->assertSee($manager->email, false)
            ->assertDontSee($student->email, false);
    }

    public function test_ticking_both_user_statuses_is_the_same_as_ticking_neither(): void
    {
        $active = User::factory()->create(['is_active' => true, 'name' => 'Active Staffer']);
        $active->assignRole('manager');
        $inactive = User::factory()->create(['is_active' => false, 'name' => 'Inactive Staffer']);
        $inactive->assignRole('manager');

        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.users.index', ['status' => ['active', 'inactive']]))
            ->assertOk()
            ->assertSee('Active Staffer', false)
            ->assertSee('Inactive Staffer', false);
    }

    /* ------------------------------- Students -------------------------------- */

    public function test_two_courses_ticked_shows_students_of_either(): void
    {
        $web = $this->category('Web');
        $laravel = $this->course('Laravel', $web);
        $react = $this->course('React', $web);
        $figma = $this->course('Figma', $web);

        $names = [];
        foreach ([[$laravel, 'Laravel Learner'], [$react, 'React Learner'], [$figma, 'Figma Learner']] as [$course, $name]) {
            $student = User::factory()->create(['name' => $name]);
            $student->assignRole('student');
            Enrollment::create(['user_id' => $student->id, 'course_id' => $course->id, 'enrolled_at' => now()->subMonth()]);
            $names[] = $name;
        }

        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.students.index', ['course' => [$laravel->id, $react->id]]))
            ->assertOk()
            ->assertSee('Laravel Learner', false)
            ->assertSee('React Learner', false)
            ->assertDontSee('Figma Learner', false);
    }

    /* ------------------------------ Pagination ------------------------------- */

    public function test_filters_survive_a_page_change(): void
    {
        $web = $this->category('Web');
        foreach (range(1, 14) as $n) {
            $this->course('Web course '.$n, $web);
        }
        $this->course('Design course', $this->category('Design'));

        $response = $this->actingAs($this->userWith('admin'))
            ->get(route('admin.courses.index', ['category' => [$web->id]]))->assertOk();

        // The pagination links carry the ticked values forward.
        $response->assertSee('category%5B0%5D='.$web->id, false);

        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.courses.index', ['category' => [$web->id], 'page' => 2]))
            ->assertOk()
            ->assertDontSee('Design course', false);
    }

    /* ------------------------------ The control ------------------------------ */

    public function test_the_dropdown_renders_real_checkboxes_not_styled_chips(): void
    {
        // The reason x-form.days gives, and the reason this one repeats it:
        // peer-checked: variants only exist if Tailwind saw them at build time,
        // and public/build is gitignored.
        $this->category('Web');

        $html = $this->actingAs($this->userWith('admin'))
            ->get(route('admin.courses.index'))->getContent();

        $this->assertStringContainsString('name="category[]"', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringNotContainsString('peer-checked:', $html);
    }

    public function test_select_all_carries_an_indeterminate_state(): void
    {
        $this->category('Web');

        $html = $this->actingAs($this->userWith('admin'))
            ->get(route('admin.courses.index'))->getContent();

        // A DOM property, not an attribute, so it is written by x-effect on
        // every change — and announced as `mixed` while partly ticked.
        $this->assertStringContainsString('$el.indeterminate = some', $html);
        $this->assertStringContainsString("some ? 'mixed'", $html);
    }

    public function test_the_slot_day_picker_is_untouched(): void
    {
        // x-form.days keeps its own file: its value is a summary this control
        // cannot produce ("Mon–Fri" from a run of days).
        $days = file_get_contents(resource_path('views/components/form/days.blade.php'));

        $this->assertStringContainsString('Every day', $days);
        $this->assertStringContainsString("name=\"{{ \$name }}[]\"", $days);

        $this->actingAs($this->userWith('super-admin'))
            ->get(route('admin.attendance-slots.create'))
            ->assertOk()
            ->assertSee('Runs on', false)
            ->assertSee('Every day', false);
    }
}
