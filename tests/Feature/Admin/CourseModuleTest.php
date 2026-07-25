<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Course;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseModuleTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->category = Category::create(['name' => 'Web Development', 'slug' => 'web-development']);
    }

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Full Stack Bootcamp',
            'category_id' => $this->category->id,
            'instructor_id' => $this->admin->id,
            'level' => 'beginner',
            'status' => 'draft',
            'duration_label' => '3 months',
            'is_free' => '1',
        ], $overrides);
    }

    public function test_blank_slug_is_generated_from_the_title(): void
    {
        $this->actingAs($this->admin)->post('/admin/courses', $this->payload());

        $this->assertSame('full-stack-bootcamp', Course::first()->slug);
    }

    public function test_typed_slug_is_kept(): void
    {
        $this->actingAs($this->admin)->post('/admin/courses', $this->payload(['slug' => 'bootcamp-2026']));

        $this->assertSame('bootcamp-2026', Course::first()->slug);
    }

    public function test_generated_slugs_never_collide(): void
    {
        $this->actingAs($this->admin)->post('/admin/courses', $this->payload());
        $this->actingAs($this->admin)->post('/admin/courses', $this->payload());

        $this->assertSame(
            ['full-stack-bootcamp', 'full-stack-bootcamp-2'],
            Course::orderBy('id')->pluck('slug')->all(),
        );
    }

    public function test_duration_label_is_saved_and_listed(): void
    {
        $this->actingAs($this->admin)->post('/admin/courses', $this->payload());

        $this->assertSame('3 months', Course::first()->duration_label);

        $this->actingAs($this->admin)->get('/admin/courses')
            ->assertOk()
            ->assertSee('Duration')
            ->assertSee('3 months')
            ->assertDontSee('>Lessons<', false);
    }

    public function test_course_list_shows_fee_in_rupees(): void
    {
        $this->actingAs($this->admin)->post('/admin/courses', $this->payload([
            'is_free' => '0',
            'price' => 45000,
        ]));

        $this->actingAs($this->admin)->get('/admin/courses')
            ->assertOk()
            ->assertSee('Fee')
            ->assertSee('Rs 45,000');
    }
}
