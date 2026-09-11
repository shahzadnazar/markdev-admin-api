<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Module;
use App\Models\Note;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One per converted upload point.
 *
 * The shared component's own edges are covered once in DropzoneTest, because
 * there is one implementation of them. What is worth a test per screen is the
 * part that is per screen and easy to get wrong in a bulk conversion: the
 * field name the controller reads, whether it is required, whether it takes
 * several files, and what it accepts. Get one of those wrong and the screen
 * still looks perfect while the upload silently stops arriving.
 *
 * These read the rendered page, so a screen that stops rendering the field at
 * all fails here rather than in production.
 */
class DropzoneSweepTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $this->category = Category::create(['name' => 'Web', 'slug' => 'web-'.Str::random(4)]);
    }

    /**
     * The <input type="file"> for a field, or a failure naming the screen.
     *
     * Matching the input tag itself is the point: a drop zone that lost its
     * input would still render a convincing dashed box.
     */
    protected function fileInput(string $html, string $name, string $where): string
    {
        preg_match_all('/<input\b[^>]*>/i', $html, $matches);

        foreach ($matches[0] as $tag) {
            if (str_contains($tag, 'name="'.$name.'"') && str_contains($tag, 'type="file"')) {
                return $tag;
            }
        }

        $this->fail("{$where} has no <input type=\"file\" name=\"{$name}\">");
    }

    public function test_the_note_file_field_survived_the_conversion(): void
    {
        $course = $this->course('Notes course');

        $html = $this->actingAs($this->admin)->get(route('admin.notes.create'))->assertOk()->getContent();
        $input = $this->fileInput($html, 'file', 'admin/notes/create');

        // Required on create, because StoreNoteRequest requires a file when
        // there is not already one on record.
        $this->assertStringContainsString('required', $input);
        $this->assertStringContainsString('.pdf', $input);

        $note = Note::create([
            'course_id' => $course->id,
            'instructor_id' => $this->admin->id,
            'title' => 'Existing',
            'file_path' => 'notes/x.pdf',
        ]);

        $editInput = $this->fileInput(
            $this->actingAs($this->admin)->get(route('admin.notes.edit', $note))->assertOk()->getContent(),
            'file',
            'admin/notes/{note}/edit',
        );

        // Not required when replacing: the note already has a file.
        $this->assertStringNotContainsString('required', $editInput);
    }

    public function test_the_assignment_attachments_field_still_takes_several_files(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.assignments.create'))->assertOk()->getContent();
        $input = $this->fileInput($html, 'attachments[]', 'admin/assignments/create');

        // The brackets matter: without them only the last file would arrive,
        // and `multiple` is what lets there be more than one in the first place.
        $this->assertStringContainsString('multiple', $input);
        $this->assertStringNotContainsString('required', $input);
    }

    public function test_the_course_thumbnail_field_survived_the_conversion(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.courses.create'))->assertOk()->getContent();
        $input = $this->fileInput($html, 'thumbnail', 'admin/courses/create');

        // nullable|image|max:4096 — optional, images only.
        $this->assertStringContainsString('accept="image/*"', $input);
        $this->assertStringNotContainsString('required', $input);
    }

    public function test_an_existing_thumbnail_is_shown_rather_than_replaced_by_an_empty_zone(): void
    {
        // Losing the current-file state would make an edit screen look like a
        // course with no thumbnail at all.
        $course = $this->course('Has a thumbnail');
        $course->forceFill(['thumbnail_path' => 'courses/cover.jpg'])->save();

        $html = $this->actingAs($this->admin)->get(route('admin.courses.edit', $course))->assertOk()->getContent();

        $this->assertStringContainsString('Current thumbnail', $html);
        $this->assertStringContainsString('drop a file here to replace it', $html);
        $this->fileInput($html, 'thumbnail', 'admin/courses/{course}/edit');
    }

    public function test_the_lesson_resource_and_video_thumbnail_fields_survived_the_conversion(): void
    {
        $lesson = $this->lesson();

        $html = $this->actingAs($this->admin)->get(route('admin.lessons.edit', $lesson))->assertOk()->getContent();

        // Two zones on one page, and they must not collide. Neither carries a
        // static `required` any more: the resource can be a LINK instead of a
        // file, so the file is only required when that kind is chosen, and a
        // hardcoded attribute would block a link from ever being submitted.
        // The obligation moved to the server, where it is conditional —
        // Rule::requiredIf on the kind, covered by LessonResourceLinkTest.
        $this->fileInput($html, 'file', 'admin/lessons/{lesson}/edit');
        $this->assertStringNotContainsString('required', $this->fileInput($html, 'thumbnail', 'admin/lessons/{lesson}/edit'));

        // Still enforced, just not by an HTML attribute.
        $this->actingAs($this->admin)
            ->from(route('admin.lessons.edit', $lesson))
            ->post(route('admin.lessons.resources.store', $lesson), ['kind' => 'file'])
            ->assertSessionHasErrors('file');
    }

    public function test_the_add_lesson_thumbnail_on_the_course_page_survived_the_conversion(): void
    {
        $course = $this->course('Course page');
        Module::create(['course_id' => $course->id, 'title' => 'Module 1', 'position' => 1]);

        $html = $this->actingAs($this->admin)->get(route('admin.courses.show', $course))->assertOk()->getContent();
        $input = $this->fileInput($html, 'thumbnail', 'admin/courses/{course}');

        $this->assertStringContainsString('accept="image/*"', $input);
        $this->assertStringNotContainsString('required', $input);
    }

    public function test_a_repeated_zone_gets_its_own_id_so_labels_do_not_collide(): void
    {
        // The add-lesson modal is rendered once per module. A drop zone is a
        // <label for>, so two zones sharing an id would both open the first
        // module's picker — the second module would silently attach its
        // thumbnail to the first. A plain input with a duplicate id merely
        // looks untidy; this one misfires.
        $course = $this->course('Two modules');
        foreach ([1, 2] as $position) {
            Module::create(['course_id' => $course->id, 'title' => "Module {$position}", 'position' => $position]);
        }

        $html = $this->actingAs($this->admin)->get(route('admin.courses.show', $course))->assertOk()->getContent();

        preg_match_all('/<input\b[^>]*type="file"[^>]*>/i', $html, $matches);
        preg_match_all('/id="([^"]+)"/', implode(' ', $matches[0]), $ids);

        // Uniqueness across EVERY zone on the page is the property that
        // matters: a drop zone is a <label for>, so two sharing an id would
        // make one of them open the other's picker. The page carries a
        // course-level resource zone as well as one thumbnail zone per module,
        // so the count is taken over the thumbnails rather than over the page.
        $thumbnails = array_values(array_filter($ids[1], fn (string $id) => str_starts_with($id, 'thumbnail-')));

        $this->assertCount(2, $thumbnails, 'expected one thumbnail zone per module');
        $this->assertSame($ids[1], array_unique($ids[1]), 'two zones on one page share an id');
        $this->assertContains('course-resource-file', $ids[1], 'the course-level zone should be here too');
    }

    public function test_the_biometric_csv_import_survived_the_conversion(): void
    {
        // It fits the component: one required file, mimes:csv,txt, max:5120 —
        // the same shape as every other single-file field. What is different
        // about it is what the controller does with the rows afterwards, and
        // none of that changed.
        $html = $this->actingAs($this->admin)->get(route('admin.biometric.punches'))->assertOk()->getContent();
        $input = $this->fileInput($html, 'file', 'admin/biometric/punches');

        $this->assertStringContainsString('required', $input);
        $this->assertStringContainsString('accept=".csv,.txt"', $input);
        // The column list is this field's own hint and has to survive too.
        $this->assertStringContainsString('biometric_id, punched_at', $html);
    }

    public function test_the_three_student_document_fields_survived_the_conversion(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.students.create'))->assertOk()->getContent();

        foreach (['photo', 'cnic_doc', 'degree_doc'] as $field) {
            $input = $this->fileInput($html, $field, 'admin/students/register');
            // All three are required while creating a student.
            $this->assertStringContainsString('required', $input, "{$field} should be required on create");
        }

        // The photo is an image; the two documents also take a PDF.
        $this->assertStringNotContainsString('application/pdf', $this->fileInput($html, 'photo', 'register'));
        $this->assertStringContainsString('application/pdf', $this->fileInput($html, 'cnic_doc', 'register'));
    }

    public function test_the_student_document_fields_stop_being_required_when_editing(): void
    {
        $student = User::factory()->create();
        $student->assignRole('student');
        StudentProfile::create([
            'user_id' => $student->id,
            'reg_no' => StudentProfile::nextRegNo(),
        ]);

        $html = $this->actingAs($this->admin)->get(route('admin.students.edit', $student))->assertOk()->getContent();

        foreach (['photo', 'cnic_doc', 'degree_doc'] as $field) {
            $this->assertStringNotContainsString(
                'required',
                $this->fileInput($html, $field, 'admin/students/{student}/edit'),
                "{$field} should be optional when editing",
            );
        }
    }

    /**
     * Every chip says what its own validator says.
     *
     * The zones and the rules live in different files, so the only thing
     * stopping them drifting is that something compares them. This walks each
     * screen, reads the max: the controller actually applies, and demands the
     * rendered chip be UploadLimits' answer for that number — which is the
     * rule, or php.ini when php.ini is smaller. A chip promising more than the
     * server takes is the lie this whole component exists to avoid.
     *
     * The KB below is the declared policy, and two tests are compared against
     * it from opposite sides: UploadLimitPolicyTest proves the CONTROLLER
     * applies that number, and this proves the VIEW prints it. Change one side
     * only and the other fails.
     *
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function chipCases(): array
    {
        return [
            // screen => [route args handled below, field id, the rule's max: in KB]
            'assignment attachments' => ['assignments.create', 'attachments', 5120],
            'course thumbnail' => ['courses.create', 'thumbnail', 1024],
            'note file' => ['notes.create', 'file', 5120],
            'biometric csv' => ['biometric.punches', 'file', 5120],
            'student photo' => ['students.create', 'photo', 1024],
            'student cnic' => ['students.create', 'cnic_doc', 5120],
            'student degree' => ['students.create', 'degree_doc', 5120],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('chipCases')]
    public function test_the_chip_matches_the_rule(string $route, string $field, int $ruleKb): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.'.$route))->assertOk()->getContent();

        $expected = 'Max '.\App\Support\UploadLimits::maxLabel($ruleKb);

        // The chips sit inside this field's own zone, so scope to it — two
        // zones on one page would otherwise cover for each other.
        $zone = $this->zoneFor($html, $field);

        $this->assertStringContainsString(
            $expected,
            $zone,
            "the {$field} zone should say \"{$expected}\" for a max:{$ruleKb} rule",
        );
    }

    /** The markup of one field's drop zone, from its input back to its label. */
    protected function zoneFor(string $html, string $field): string
    {
        $inputAt = strpos($html, 'id="'.$field.'"');
        $this->assertNotFalse($inputAt, "no zone rendered for {$field}");

        $labelAt = strrpos(substr($html, 0, $inputAt), '<label');
        $endAt = strpos($html, '</label>', $inputAt);

        return substr($html, $labelAt, $endAt - $labelAt);
    }

    protected function course(string $title): Course
    {
        return Course::create([
            'title' => $title,
            'slug' => Str::slug($title.'-'.Str::random(4)),
            'excerpt' => 'x',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'is_free' => true,
            'category_id' => $this->category->id,
        ]);
    }

    protected function lesson(): Lesson
    {
        $course = $this->course('Lesson course');
        $module = Module::create(['course_id' => $course->id, 'title' => 'Module 1', 'position' => 1]);

        return Lesson::create([
            'module_id' => $module->id,
            'course_id' => $course->id,
            'title' => 'Lesson 1',
            'type' => 'video',
            'position' => 1,
        ]);
    }
}
