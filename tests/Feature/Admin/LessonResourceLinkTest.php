<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonResource;
use App\Models\Module;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A lesson resource is a file OR a link.
 *
 * `kind` is a stored discriminator rather than an inference from which column
 * happens to be null. file_path had to become nullable for links, and once
 * both it and url are nullable, "exactly one of these is set" is an invariant
 * with nothing enforcing it — a row with both, or neither, is representable.
 * These tests pin the invariant and the two behaviours that hang off it: a
 * file still downloads, a link opens.
 *
 * Permissions are the existing matrix. No new ones were needed: every route in
 * the section already sits behind courses.* or lessons.*, and resources ride
 * on lessons.update.
 */
class LessonResourceLinkTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('public');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $course = Course::create([
            'title' => 'Laravel', 'slug' => 'laravel-'.Str::random(6), 'excerpt' => 'x',
            'level' => 'beginner', 'status' => 'published', 'published_at' => now()->subDay(),
            'is_free' => true,
            'category_id' => Category::create(['name' => 'Web', 'slug' => 'web-'.Str::random(4)])->id,
        ]);
        $module = Module::create(['course_id' => $course->id, 'title' => 'M1', 'position' => 1]);
        $this->lesson = Lesson::create([
            'module_id' => $module->id, 'course_id' => $course->id,
            'title' => 'Lesson 1', 'type' => 'video', 'position' => 1,
        ]);
    }

    protected function addResource(array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)
            ->from(route('admin.lessons.edit', $this->lesson))
            ->post(route('admin.lessons.resources.store', $this->lesson), $payload);
    }

    // ------------------------------------------------------------ the kinds

    public function test_a_file_resource_still_uploads_and_downloads(): void
    {
        $this->addResource([
            'kind' => 'file',
            'file' => UploadedFile::fake()->create('slides.pdf', 64, 'application/pdf'),
        ])->assertRedirect();

        $resource = LessonResource::firstOrFail();

        $this->assertSame('file', $resource->kind);
        $this->assertNotNull($resource->file_path);
        $this->assertNull($resource->url);
        Storage::disk('public')->assertExists($resource->file_path);
        // target_url is the one field a caller follows whatever the kind.
        $this->assertSame($resource->file_url, $resource->target_url);
    }

    public function test_a_link_resource_is_stored_with_no_file(): void
    {
        $this->addResource([
            'kind' => 'link',
            'link_name' => 'The Laravel docs',
            'link_url' => 'https://laravel.com/docs',
        ])->assertRedirect();

        $resource = LessonResource::firstOrFail();

        $this->assertSame('link', $resource->kind);
        $this->assertSame('https://laravel.com/docs', $resource->url);
        $this->assertNull($resource->file_path);
        $this->assertSame('https://laravel.com/docs', $resource->target_url);
        $this->assertFalse($resource->is_youtube);
    }

    public function test_a_youtube_link_is_recognised_by_host_not_by_substring(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=abc' => true,
            'https://youtu.be/abc' => true,
            'https://m.youtube.com/watch?v=abc' => true,
            'https://example.com/watch?v=abc' => false,
            // The one a substring check would get wrong.
            'https://youtube.com.phishing.example/watch' => false,
        ] as $url => $expected) {
            $resource = new LessonResource(['kind' => 'link', 'url' => $url]);

            $this->assertSame($expected, $resource->is_youtube, $url);
        }
    }

    public function test_a_link_must_be_http_or_https(): void
    {
        // A resource list that can carry javascript: or data: is a stored-XSS
        // delivery mechanism dressed as a reading list — students click these.
        foreach (['javascript:alert(1)', 'data:text/html,<script>alert(1)</script>'] as $url) {
            $this->addResource(['kind' => 'link', 'link_name' => 'bad', 'link_url' => $url])
                ->assertSessionHasErrors('link_url');
        }

        $this->assertSame(0, LessonResource::count());
    }

    public function test_each_kind_requires_its_own_field(): void
    {
        // The reason kind is stored: the validator can require the right field
        // and say why, rather than guessing from what arrived.
        $this->addResource(['kind' => 'link', 'name' => 'no url'])->assertSessionHasErrors('link_url');
        $this->addResource(['kind' => 'file'])->assertSessionHasErrors('file');
        $this->addResource(['kind' => 'link', 'link_url' => 'https://example.com'])
            ->assertSessionHasErrors('link_name');
    }

    public function test_deleting_a_link_does_not_look_for_a_file(): void
    {
        $this->addResource(['kind' => 'link', 'link_name' => 'Docs', 'link_url' => 'https://laravel.com'])
            ->assertRedirect();
        $resource = LessonResource::firstOrFail();

        $this->actingAs($this->admin)
            ->delete(route('admin.lessons.resources.destroy', [$this->lesson, $resource]))
            ->assertRedirect();

        $this->assertSame(0, LessonResource::count());
    }

    public function test_both_kinds_render_in_the_lesson_editor(): void
    {
        $this->addResource(['kind' => 'file', 'file' => UploadedFile::fake()->create('a.pdf', 8)]);
        $this->addResource(['kind' => 'link', 'link_name' => 'A video', 'link_url' => 'https://youtu.be/abc']);

        $page = $this->actingAs($this->admin)
            ->get(route('admin.lessons.edit', $this->lesson))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('a.pdf', $page);
        $this->assertStringContainsString('A video', $page);
        $this->assertStringContainsString('YOUTUBE', $page);
    }

    // ------------------------------------------------------- the permissions

    public function test_a_role_without_lessons_update_gets_403_on_the_routes(): void
    {
        // The rule, not the button. A manager holds courses.update but not
        // lessons.update, so the lesson editor and both resource routes are
        // closed to them.
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->actingAs($manager)->get(route('admin.lessons.edit', $this->lesson))->assertForbidden();
        $this->addResource(['kind' => 'link', 'link_name' => 'x', 'link_url' => 'https://example.com'], $manager)
            ->assertForbidden();

        $this->assertSame(0, LessonResource::count());
    }

    public function test_the_builder_hides_the_buttons_that_role_cannot_use(): void
    {
        // And the button matches the rule on the page they CAN reach.
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $page = $this->actingAs($manager)
            ->get(route('admin.courses.show', $this->lesson->course_id))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            route('admin.lessons.edit', $this->lesson),
            $page,
            'a viewer without lessons.update should not be offered the lesson editor',
        );
    }

    public function test_granting_the_permission_opens_both_the_button_and_the_route(): void
    {
        // Super-admin controls this through the existing matrix — no second
        // mechanism, no new permission.
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->actingAs($manager)->get(route('admin.lessons.edit', $this->lesson))->assertForbidden();

        $manager->givePermissionTo('lessons.update');
        $manager->refresh();

        $this->actingAs($manager)->get(route('admin.lessons.edit', $this->lesson))->assertOk();

        $page = $this->actingAs($manager)
            ->get(route('admin.courses.show', $this->lesson->course_id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('admin.lessons.edit', $this->lesson), $page);
    }
}
