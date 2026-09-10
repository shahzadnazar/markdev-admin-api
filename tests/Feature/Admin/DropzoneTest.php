<?php

namespace Tests\Feature\Admin;

use App\Models\Assignment;
use App\Models\Category;
use App\Models\Course;
use App\Models\Note;
use App\Models\User;
use App\Support\UploadLimits;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * The drop zone is still a file input.
 *
 * Everything visible about `x-form.dropzone` — the dashed border, the chips,
 * the card, the drag highlight — is decoration around one <input type="file">.
 * That is not a detail: it is the whole reason this is safe to put on ten
 * forms. The input is what makes the form post without JavaScript and without
 * a controller change, what makes `required` bite in the browser, what keeps
 * the field in the tab order, and what a screen reader announces.
 *
 * A div with a drop handler would look identical in a screenshot and break all
 * four. So these test the input, not the picture.
 *
 * The per-screen conversions are in DropzoneSweepTest; the edges of the one
 * implementation live here, since there is one implementation of them.
 */
class DropzoneTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    /** Render the component on its own, so these do not depend on any one screen. */
    protected function render(string $attributes): string
    {
        // ShareErrorsFromSession puts this on every web view; Blade::render
        // runs outside the middleware stack, so the test supplies it.
        view()->share('errors', new ViewErrorBag);

        return Blade::render("<x-form.dropzone {$attributes} />");
    }

    public function test_it_renders_a_real_file_input_not_a_decorated_div(): void
    {
        $html = $this->render('name="file" label="File" :max-kb="1024"');

        $this->assertStringContainsString('type="file"', $html);
        $this->assertStringContainsString('name="file"', $html);
        // sr-only, not hidden and not display:none — those take a control out
        // of the tab order and out of the accessibility tree.
        $this->assertStringContainsString('class="sr-only"', $html);
        $this->assertStringNotContainsString('type="hidden"', $html);
    }

    public function test_the_zone_is_a_label_bound_to_that_input(): void
    {
        // This is why clicking anywhere on the zone opens the picker with no
        // click handler at all, and why the input keeps its own focus ring.
        $html = $this->render('name="thumbnail" :max-kb="2048"');

        $this->assertStringContainsString('for="thumbnail"', $html);
        $this->assertStringContainsString('id="thumbnail"', $html);
    }

    public function test_required_reaches_the_input_so_a_no_js_submit_is_refused_too(): void
    {
        $required = $this->render('name="file" :max-kb="1024" required');
        $optional = $this->render('name="file" :max-kb="1024"');

        $this->assertMatchesRegularExpression('/<input[^>]*\brequired\b/', $required);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*\brequired\b/', $optional);
    }

    public function test_the_input_is_never_inside_an_alpine_template(): void
    {
        // Alpine destroys and rebuilds a <template x-if> body when the
        // condition flips, so an input in there is a different element each
        // time: $refs.input would follow the new node and a FileList assigned
        // on drop would go with the discarded one. The React half of this had
        // exactly that bug — the card showed the file and the input carried
        // nothing — so the rule is pinned on both sides.
        $source = file_get_contents(resource_path('views/components/form/dropzone.blade.php'));

        // Comments first: this file explains the rule it is being tested for.
        $code = preg_replace('/\{\{--.*?--\}\}/s', '', $source);

        $this->assertSame(1, substr_count($code, '<input '), 'there must be exactly one input');

        // Nothing opens before it that has not also closed.
        $before = substr($code, 0, strpos($code, '<input '));
        $this->assertSame(
            substr_count($before, '<template'),
            substr_count($before, '</template>'),
            'the input must not sit inside a <template>',
        );
    }

    public function test_take_is_told_whether_it_is_a_drop_or_a_change(): void
    {
        /*
         * The bug this guards, in full, because the fix looks like a
         * refactor and will be "simplified" back otherwise.
         *
         * take() used to append its argument to whatever the input already
         * held. That is right for a drop — the input has never seen those
         * files — and wrong for a change, because the browser has ALREADY
         * written the new selection onto the input and thrown the old one
         * away. So choosing one file through Browse counted it twice: once
         * from the input, once from the argument.
         *
         * And it was not a doubled picture. commit() writes the list back
         * onto the input through a DataTransfer, so the input carried two,
         * the form posted two, and the controller stored two files on disk
         * under two rows. Measured on /admin/assignments/create.
         *
         * Alpine cannot run in PHPUnit, so this reads the source: the append
         * has to stay gated on a drop, and every call site has to say which
         * event it is. The behaviour itself is covered by
         * test_two_posted_files_are_stored_as_two below and by the browser
         * matrix in the commit message.
         */
        $code = $this->componentSource();

        // Both call sites declare themselves …
        $this->assertStringContainsString("take(\$event.dataTransfer.files, 'drop')", $code);
        $this->assertStringContainsString("take(\$event.target.files, 'change')", $code);

        // … take() reads the declaration …
        $this->assertMatchesRegularExpression('/take\(\s*list\s*,\s*mode\s*\)/', $code);

        // … and appending happens only on a drop. An unconditional
        // `[...this.files(), ...kept]` is the original bug verbatim.
        $this->assertStringContainsString("mode === 'drop'", $code);
        $this->assertDoesNotMatchRegularExpression(
            '/this\.commit\(\s*this\.multiple\s*\?\s*\[\s*\.\.\.this\.files\(\)/',
            $code,
            'take() is appending the input\'s own files again — Browse will duplicate',
        );
    }

    public function test_a_single_posted_file_is_stored_once(): void
    {
        // The consequence of the duplicate, at the only layer PHPUnit can
        // see: one file chosen must not become two attachments.
        Storage::fake('public');

        $assignment = $this->postAssignment('One attachment', [
            UploadedFile::fake()->create('brief.pdf', 12, 'application/pdf'),
        ]);

        $this->assertCount(1, $assignment->attachments);
        $this->assertSame('brief.pdf', $assignment->attachments->first()->name);
    }

    public function test_two_posted_files_are_stored_as_two(): void
    {
        // The other half of the same guard: a fix that deduplicates on the
        // server would hide the bug and break genuinely picking two files.
        Storage::fake('public');

        $assignment = $this->postAssignment('Two attachments', [
            UploadedFile::fake()->create('brief.pdf', 12, 'application/pdf'),
            UploadedFile::fake()->create('rubric.docx', 8),
        ]);

        $this->assertCount(2, $assignment->attachments);
        $this->assertEqualsCanonicalizing(
            ['brief.pdf', 'rubric.docx'],
            $assignment->attachments->pluck('name')->all(),
        );
    }

    public function test_the_chips_come_from_props_rather_than_being_hardcoded(): void
    {
        $html = $this->render('name="file" accept=".csv,.txt" accept-label="CSV, TXT" :max-kb="64"');

        $this->assertStringContainsString('CSV, TXT', $html);
        $this->assertStringContainsString('Max 64 KB', $html);
        $this->assertStringContainsString('accept=".csv,.txt"', $html);
    }

    public function test_the_size_chip_never_promises_more_than_php_will_take(): void
    {
        // The rule here is 20 MB. On a box where upload_max_filesize is
        // smaller, the chip has to say the smaller number: PHP discards the
        // file before Laravel runs, and the user would be told the field is
        // required for a file they definitely attached.
        $html = $this->render('name="file" :max-kb="20480"');

        $this->assertStringContainsString('Max '.UploadLimits::maxLabel(20480), $html);

        if (UploadLimits::cappedByPhp(20480)) {
            $this->assertStringNotContainsString('Max 20 MB', $html);
        }
    }

    public function test_a_multiple_field_reports_errors_under_the_key_the_validator_uses(): void
    {
        // `attachments[]` posts as an array, so the validator keys its messages
        // `attachments.*` — the component works that out rather than making
        // every call site remember.
        $html = $this->render('name="attachments[]" multiple :max-kb="1024"');

        $this->assertStringContainsString('name="attachments[]"', $html);
        $this->assertStringContainsString('id="attachments"', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*\bmultiple\b/', $html);
    }

    public function test_an_upload_through_a_converted_form_still_reaches_the_controller(): void
    {
        Storage::fake('public');
        $course = $this->course();

        $this->actingAs($this->admin)
            ->post(route('admin.notes.store'), [
                'course_id' => $course->id,
                'title' => 'Week 1 handout',
                'file' => UploadedFile::fake()->create('handout.pdf', 12, 'application/pdf'),
            ])
            ->assertRedirect();

        // Same field name, same controller, same disk — the zone changed how
        // the file is chosen, nothing about where it goes.
        $this->assertNotNull(Note::where('title', 'Week 1 handout')->first()?->file_path);
    }

    public function test_the_server_still_refuses_an_oversized_file(): void
    {
        // The client-side message is a courtesy. This is the rule.
        Storage::fake('public');
        $course = $this->course();

        $this->actingAs($this->admin)
            ->post(route('admin.notes.store'), [
                'course_id' => $course->id,
                'title' => 'Too big',
                'file' => UploadedFile::fake()->create('huge.pdf', 20481, 'application/pdf'),
            ])
            ->assertSessionHasErrors('file');

        $this->assertNull(Note::where('title', 'Too big')->first());
    }

    public function test_the_server_still_refuses_a_file_of_the_wrong_type(): void
    {
        Storage::fake('public');
        $course = $this->course();

        $this->actingAs($this->admin)
            ->post(route('admin.notes.store'), [
                'course_id' => $course->id,
                'title' => 'Wrong type',
                'file' => UploadedFile::fake()->create('payload.exe', 4, 'application/x-msdownload'),
            ])
            ->assertSessionHasErrors('file');

        $this->assertNull(Note::where('title', 'Wrong type')->first());
    }

    public function test_the_server_still_refuses_a_missing_file_where_one_is_required(): void
    {
        Storage::fake('public');
        $course = $this->course();

        $this->actingAs($this->admin)
            ->post(route('admin.notes.store'), [
                'course_id' => $course->id,
                'title' => 'No file at all',
            ])
            ->assertSessionHasErrors('file');
    }

    /**
     * The component's JavaScript with its comments taken out.
     *
     * This file explains at length why the append is conditional, so a guard
     * reading the raw source would match its own explanation and pass on
     * broken code.
     */
    protected function componentSource(): string
    {
        $source = file_get_contents(resource_path('views/components/form/dropzone.blade.php'));

        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        $source = preg_replace('#/\*.*?\*/#s', '', $source);

        return preg_replace('#^\s*//.*$#m', '', $source);
    }

    /** Create an assignment with attachments through the real controller. */
    protected function postAssignment(string $title, array $files): Assignment
    {
        $course = $this->course();

        $this->actingAs($this->admin)
            ->post(route('admin.assignments.store'), [
                'course_id' => $course->id,
                'title' => $title,
                'max_score' => 100,
                'attachments' => $files,
            ])
            ->assertRedirect();

        $assignment = Assignment::where('title', $title)->firstOrFail();

        return $assignment->load('attachments');
    }

    protected function course(): Course
    {
        $category = Category::create(['name' => 'Web', 'slug' => 'web-'.Str::random(4)]);

        return Course::create([
            'title' => 'Dropzone course',
            'slug' => 'dropzone-'.Str::random(6),
            'excerpt' => 'x',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'is_free' => true,
            'category_id' => $category->id,
        ]);
    }

    protected function nextTag(string $code, int $offset, string $open, string $close, int $depth): ?array
    {
        return null;
    }
}
