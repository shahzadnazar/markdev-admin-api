<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Course;
use App\Models\LessonResource;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Course-level resources on the Course Content list.
 *
 * The panel below the courses table shows every course-level resource the
 * viewer may see, and its add form has to ask which course — the page is not
 * scoped to one. Both of those are places a scoping leak fits, and one already
 * happened here: the category dropdown was fed by a second, unscoped query
 * while the list beneath it was scoped (77b6fd1). So the panel and the picker
 * are pinned to the instructor's own courses from both directions.
 */
class CourseListResourcesTest extends TestCase
{
    use RefreshDatabase;

    protected Category $web;

    protected Category $design;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->web = Category::create(['name' => 'Web', 'slug' => 'web-'.Str::random(4)]);
        $this->design = Category::create(['name' => 'Design', 'slug' => 'design-'.Str::random(4)]);
    }

    protected function userWith(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    protected function course(string $title, ?Category $category = null, ?User $instructor = null): Course
    {
        return Course::create([
            'title' => $title,
            'slug' => Str::slug($title.'-'.Str::random(4)),
            'excerpt' => 'x',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'is_free' => true,
            'category_id' => ($category ?? $this->web)->id,
            'instructor_id' => $instructor?->id,
        ]);
    }

    protected function link(Course $course, string $name): LessonResource
    {
        return $course->resources()->create([
            'name' => $name,
            'kind' => 'link',
            'url' => 'https://laravel.com/'.Str::slug($name),
        ]);
    }

    /* ------------------------------ what it shows ----------------------------- */

    public function test_an_admin_sees_the_panel_and_every_resource(): void
    {
        $this->link($this->course('Laravel', $this->web), 'Laravel syllabus');
        $this->link($this->course('Figma', $this->design), 'Figma handbook');

        $page = $this->actingAs($this->userWith('admin'))
            ->get(route('admin.courses.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Course resources', $page);
        $this->assertStringContainsString('Laravel syllabus', $page);
        $this->assertStringContainsString('Figma handbook', $page);
    }

    public function test_each_row_names_the_course_it_belongs_to(): void
    {
        // On a list covering every course the resource's own name does not say
        // which course it is attached to.
        $this->link($this->course('Advanced Laravel', $this->web), 'Syllabus');

        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.courses.index'))
            ->assertOk()
            ->assertSee('Syllabus', false)
            ->assertSee('Advanced Laravel', false);
    }

    public function test_lesson_level_resources_stay_off_this_panel(): void
    {
        $course = $this->course('Laravel', $this->web);
        $module = \App\Models\Module::create(['course_id' => $course->id, 'title' => 'M1', 'position' => 1]);
        $lesson = \App\Models\Lesson::create([
            'module_id' => $module->id, 'course_id' => $course->id, 'title' => 'L1',
            'type' => 'video', 'duration_minutes' => 5, 'position' => 1, 'is_preview' => false,
        ]);
        $lesson->resources()->create(['name' => 'Lesson worksheet', 'kind' => 'link', 'url' => 'https://a.test']);
        $this->link($course, 'Course syllabus');

        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.courses.index'))
            ->assertOk()
            ->assertSee('Course syllabus', false)
            ->assertDontSee('Lesson worksheet', false);
    }

    public function test_a_resource_on_a_trashed_course_drops_out(): void
    {
        $course = $this->course('Going away', $this->web);
        $this->link($course, 'Doomed syllabus');
        $course->delete();

        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.courses.index'))
            ->assertOk()
            ->assertDontSee('Doomed syllabus', false);
    }

    /* ------------------------------- the scoping ------------------------------ */

    public function test_an_instructor_sees_only_their_own_courses_resources(): void
    {
        $instructor = $this->userWith('instructor');

        $this->link($this->course('Mine', $this->web, $instructor), 'My syllabus');
        $this->link($this->course('Theirs', $this->design, $this->userWith('instructor')), 'Their syllabus');

        $this->actingAs($instructor)
            ->get(route('admin.courses.index'))
            ->assertOk()
            ->assertSee('My syllabus', false)
            ->assertDontSee('Their syllabus', false);
    }

    /**
     * Pinned on the controller, not on the rendered HTML.
     *
     * A page can fail to print a row for reasons that have nothing to do with
     * scoping, so assertDontSee alone cannot tell "never queried" from "queried
     * and not shown". This asserts the collection the view was handed.
     */
    public function test_the_panel_is_handed_only_the_instructors_own_resources(): void
    {
        $instructor = $this->userWith('instructor');
        $mine = $this->link($this->course('Mine', $this->web, $instructor), 'My syllabus');
        $this->link($this->course('Theirs', $this->design, $this->userWith('instructor')), 'Their syllabus');

        $this->actingAs($instructor)
            ->get(route('admin.courses.index'))
            ->assertOk()
            ->assertViewHas('resources', fn ($resources) => $resources->pluck('id')->all() === [$mine->id]);
    }

    public function test_the_course_picker_offers_only_courses_the_viewer_may_edit(): void
    {
        $instructor = $this->userWith('instructor');
        $mine = $this->course('Mine', $this->web, $instructor);
        $this->course('Theirs', $this->design, $this->userWith('instructor'));

        $this->actingAs($instructor)
            ->get(route('admin.courses.index'))
            ->assertOk()
            ->assertViewHas(
                'selectableCourses',
                fn ($courses) => $courses->pluck('id')->all() === [$mine->id],
            );
    }

    public function test_an_admins_picker_offers_everything(): void
    {
        $this->course('Mine', $this->web);
        $this->course('Theirs', $this->design);

        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.courses.index'))
            ->assertOk()
            ->assertViewHas('selectableCourses', fn ($courses) => $courses->count() === 2);
    }

    /* -------------------------------- the gating ------------------------------ */

    public function test_a_role_without_courses_update_gets_no_add_or_delete_controls(): void
    {
        // A student holds neither courses.view nor courses.update, so the one
        // that proves the @can rather than the route middleware is a viewer who
        // can see the page: a manager stripped of courses.update.
        $manager = $this->userWith('manager');
        $manager->revokePermissionTo('courses.update');
        $manager->roles()->first()->revokePermissionTo('courses.update');
        $manager->forgetCachedPermissions();

        $course = $this->course('Laravel', $this->web);
        $resource = $this->link($course, 'Syllabus');

        $page = $this->actingAs($manager)
            ->get(route('admin.courses.index'))
            ->assertOk()
            ->getContent();

        // The list itself is still readable.
        $this->assertStringContainsString('Syllabus', $page);
        // The controls are not there.
        $this->assertStringNotContainsString(
            route('admin.courses.resources.destroy', [$course, $resource]),
            $page,
        );
        $this->assertStringNotContainsString('__COURSE__', $page, 'the add form should not render at all');
    }

    public function test_a_role_without_courses_update_is_refused_by_the_route_too(): void
    {
        // Buttons AND routes: hiding the form is not a permission check.
        $student = $this->userWith('student');
        $course = $this->course('Laravel', $this->web);
        $resource = $this->link($course, 'Syllabus');

        $this->actingAs($student)
            ->post(route('admin.courses.resources.store', $course), [
                'kind' => 'link', 'link_name' => 'x', 'link_url' => 'https://a.test',
            ])
            ->assertForbidden();

        $this->actingAs($student)
            ->delete(route('admin.courses.resources.destroy', [$course, $resource]))
            ->assertForbidden();

        $this->assertSame(1, LessonResource::count());
    }

    public function test_an_instructor_posting_to_another_categorys_course_is_refused(): void
    {
        $instructor = $this->userWith('instructor');
        $this->course('Mine', $this->web, $instructor);
        $theirs = $this->course('Theirs', $this->design, $this->userWith('instructor'));

        $this->actingAs($instructor)
            ->post(route('admin.courses.resources.store', $theirs), [
                'kind' => 'link', 'link_name' => 'Sneaky', 'link_url' => 'https://a.test',
            ])
            ->assertForbidden();

        $this->assertSame(0, LessonResource::count());
    }

    /* ------------------------------- the same rules --------------------------- */

    public function test_a_javascript_url_is_rejected_from_this_page_too(): void
    {
        // One set of rules, in StoresResources — this page reaches the same
        // endpoint, so it inherits them rather than restating them.
        $course = $this->course('Laravel', $this->web);

        $this->actingAs($this->userWith('admin'))
            ->from(route('admin.courses.index'))
            ->post(route('admin.courses.resources.store', $course), [
                'kind' => 'link', 'link_name' => 'bad', 'link_url' => 'javascript:alert(1)',
            ])
            ->assertSessionHasErrors('link_url');

        $this->assertSame(0, LessonResource::count());
    }

    public function test_adding_from_the_list_lands_on_the_chosen_course(): void
    {
        $target = $this->course('Target', $this->design);
        $this->course('Other', $this->web);

        $this->actingAs($this->userWith('admin'))
            ->from(route('admin.courses.index'))
            ->post(route('admin.courses.resources.store', $target), [
                'kind' => 'link', 'link_name' => 'Syllabus', 'link_url' => 'https://laravel.com/docs',
            ])
            ->assertRedirect(route('admin.courses.index'));

        $resource = LessonResource::firstOrFail();
        $this->assertSame($target->id, $resource->course_id);
        $this->assertNull($resource->lesson_id);
    }

    /* --------------------------- the table still works ------------------------ */

    public function test_the_courses_table_its_filters_and_pagination_still_work(): void
    {
        foreach (range(1, 14) as $n) {
            $this->course('Web course '.$n, $this->web);
        }
        $this->course('Design course', $this->design);

        $admin = $this->userWith('admin');

        // The table paginates at 10, independently of the resources panel.
        $this->actingAs($admin)->get(route('admin.courses.index'))->assertOk()
            ->assertViewHas('courses', fn ($courses) => $courses->count() === 10 && $courses->total() === 15);

        // A category filter still narrows it.
        $this->actingAs($admin)
            ->get(route('admin.courses.index', ['category' => [$this->web->id], 'partial' => 1]))
            ->assertOk()
            ->assertDontSee('Design course', false);

        // And the second page is still the second page.
        $this->actingAs($admin)
            ->get(route('admin.courses.index', ['page' => 2]))
            ->assertOk()
            ->assertViewHas('courses', fn ($courses) => $courses->currentPage() === 2 && $courses->count() === 5);
    }

    /**
     * Two paginators, two page keys.
     *
     * Both lists live on one page. Sharing ?page would make paging the courses
     * silently page the resources as well, and there are far more of one than
     * the other.
     */
    public function test_paging_the_resources_does_not_page_the_courses(): void
    {
        foreach (range(1, 14) as $n) {
            $this->course('Web course '.$n, $this->web);
        }

        $course = $this->course('Resourceful', $this->web);
        foreach (range(1, 20) as $n) {
            $this->link($course, 'Resource '.$n);
        }

        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.courses.index', ['resource_page' => 2]))
            ->assertOk()
            ->assertViewHas('courses', fn ($courses) => $courses->currentPage() === 1)
            ->assertViewHas('resources', fn ($resources) => $resources->currentPage() === 2);
    }
}
