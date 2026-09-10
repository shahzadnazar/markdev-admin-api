<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Module;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Two limits, applied by what a field ACCEPTS.
 *
 *   accepts only images          -> 1 MB
 *   accepts anything else        -> 5 MB
 *
 * "Accepts", not "might receive": a field taking image-or-PDF is not an image
 * field, it is an evidence field that happens to take images, and holding a
 * phone photo of a bank slip to 1 MB would refuse most real ones. That single
 * question decides every rule below, which is why they are tested as one
 * policy rather than eleven unrelated numbers.
 *
 * Archives are accepted on attachment-style fields ONLY — assignment
 * attachments, lesson resources, the portal's submission. Those three have no
 * mimes list at all, so a zip was already accepted and adding `mimes:zip`
 * would have FORBIDDEN the pdfs and docs they exist for. Every other field
 * refuses an archive through the mimes list or `image`, and these prove it
 * with a real .zip rather than a fabricated mime string, because a browser may
 * report one as application/x-zip-compressed or application/octet-stream.
 */
class UploadLimitPolicyTest extends TestCase
{
    use RefreshDatabase;

    /** The limits, in kilobytes, exactly as the validator spells them. */
    private const IMAGE_MAX_KB = 1024;

    private const OTHER_MAX_KB = 5120;

    protected User $admin;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('public');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $this->category = Category::create(['name' => 'Web', 'slug' => 'web-'.Str::random(4)]);
    }

    /**
     * A genuine archive on disk.
     *
     * Not UploadedFile::fake()->create('x.zip'), which is an empty file with a
     * name: `mimes:zip` consults the guessed type as well as the extension,
     * so a fake would prove nothing about whether a real zip passes.
     */
    protected function realZip(int $kilobytes): UploadedFile
    {
        $dir = sys_get_temp_dir().'/dropzone-'.Str::random(8);
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/payload.bin', str_repeat('z', $kilobytes * 1024));

        $path = $dir.'/work.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        // Stored, not deflated: 'z' repeated compresses to almost nothing, and
        // the point of the size cases is the size.
        $zip->addFile($dir.'/payload.bin', 'payload.bin');
        $zip->setCompressionName('payload.bin', \ZipArchive::CM_STORE);
        $zip->close();

        return new UploadedFile($path, 'work.zip', null, null, true);
    }

    /** Does this rule set accept this file? */
    protected function accepts(array $rules, UploadedFile $file): bool
    {
        return Validator::make(['f' => $file], ['f' => $rules])->passes();
    }

    protected function image(int $kilobytes): UploadedFile
    {
        return UploadedFile::fake()->create('photo.jpg', $kilobytes, 'image/jpeg');
    }

    // ---------------------------------------------------------------- sizes

    public function test_a_900_kb_image_is_accepted_on_an_image_field(): void
    {
        $this->assertTrue($this->accepts(['image', 'max:'.self::IMAGE_MAX_KB], $this->image(900)));
    }

    public function test_a_1_and_a_half_mb_image_is_refused_on_an_image_field(): void
    {
        $this->assertFalse($this->accepts(['image', 'max:'.self::IMAGE_MAX_KB], $this->image(1536)));
    }

    public function test_a_4_mb_zip_is_accepted_on_an_attachment_field(): void
    {
        $this->assertTrue($this->accepts(['file', 'max:'.self::OTHER_MAX_KB], $this->realZip(4096)));
    }

    public function test_a_6_mb_zip_is_refused_on_an_attachment_field(): void
    {
        $this->assertFalse($this->accepts(['file', 'max:'.self::OTHER_MAX_KB], $this->realZip(6144)));
    }

    public function test_a_zip_is_refused_on_an_image_only_field(): void
    {
        // `image` is what refuses it — no mimes list needed and none added.
        $this->assertFalse($this->accepts(['image', 'max:'.self::IMAGE_MAX_KB], $this->realZip(64)));
    }

    // ------------------------------------------------- one per changed rule

    public function test_admin_assignment_attachments_take_5_mb_and_an_archive(): void
    {
        $course = $this->course();

        $this->actingAs($this->admin)->post(route('admin.assignments.store'), [
            'course_id' => $course->id, 'title' => 'With a zip', 'max_score' => 100,
            'attachments' => [$this->realZip(4096)],
        ])->assertRedirect();

        $this->assertCount(1, \App\Models\Assignment::where('title', 'With a zip')->firstOrFail()->attachments);

        $this->actingAs($this->admin)->post(route('admin.assignments.store'), [
            'course_id' => $course->id, 'title' => 'Too big', 'max_score' => 100,
            'attachments' => [$this->realZip(6144)],
        ])->assertSessionHasErrors('attachments.0');
    }

    public function test_the_course_thumbnail_is_an_image_field_at_1_mb(): void
    {
        $this->actingAs($this->admin)->post(route('admin.courses.store'), [
            ...$this->courseFields('Big cover'), 'thumbnail' => $this->image(1536),
        ])->assertSessionHasErrors('thumbnail');

        $this->actingAs($this->admin)->post(route('admin.courses.store'), [
            ...$this->courseFields('Small cover'), 'thumbnail' => $this->image(900),
        ])->assertSessionDoesntHaveErrors('thumbnail');
    }

    public function test_the_lesson_video_thumbnail_is_an_image_field_at_1_mb(): void
    {
        $lesson = $this->lesson();

        $this->actingAs($this->admin)->put(route('admin.lessons.update', $lesson), [
            'title' => 'Lesson 1', 'type' => 'video', 'thumbnail' => $this->image(1536),
        ])->assertSessionHasErrors('thumbnail');

        $this->actingAs($this->admin)->put(route('admin.lessons.update', $lesson), [
            'title' => 'Lesson 1', 'type' => 'video', 'thumbnail' => $this->image(900),
        ])->assertSessionDoesntHaveErrors('thumbnail');
    }

    public function test_a_lesson_resource_takes_5_mb_and_an_archive(): void
    {
        $lesson = $this->lesson();

        $this->actingAs($this->admin)
            ->post(route('admin.lessons.resources.store', $lesson), ['file' => $this->realZip(4096)])
            ->assertSessionDoesntHaveErrors('file');

        $this->actingAs($this->admin)
            ->post(route('admin.lessons.resources.store', $lesson), ['file' => $this->realZip(6144)])
            ->assertSessionHasErrors('file');
    }

    public function test_a_note_takes_5_mb_and_still_refuses_an_archive(): void
    {
        $course = $this->course();

        // A note is one readable document; the download route names it by
        // extension and an archive has nothing to map to.
        $this->actingAs($this->admin)->post(route('admin.notes.store'), [
            'course_id' => $course->id, 'title' => 'Zipped', 'file' => $this->realZip(64),
        ])->assertSessionHasErrors('file');

        $this->actingAs($this->admin)->post(route('admin.notes.store'), [
            'course_id' => $course->id, 'title' => 'Big handout',
            'file' => UploadedFile::fake()->create('h.pdf', 6144, 'application/pdf'),
        ])->assertSessionHasErrors('file');

        $this->actingAs($this->admin)->post(route('admin.notes.store'), [
            'course_id' => $course->id, 'title' => 'Fine handout',
            'file' => UploadedFile::fake()->create('h.pdf', 4096, 'application/pdf'),
        ])->assertSessionDoesntHaveErrors('file');
    }

    public function test_student_documents_split_by_what_they_accept(): void
    {
        // Through the real controller, not a copy of its rules: posting only
        // the files leaves the other fields invalid too, which is fine — each
        // assertion names the key it cares about.
        $route = route('admin.students.store');

        // photo takes images only, so 1 MB.
        $this->actingAs($this->admin)->post($route, ['photo' => $this->image(1536)])
            ->assertSessionHasErrors('photo');
        $this->actingAs($this->admin)->post($route, ['photo' => $this->image(900)])
            ->assertSessionDoesntHaveErrors('photo');

        // The documents also take a PDF, so they are "other files" at 5 MB —
        // a phone photo of a card was routinely over the old 1 MB.
        $this->actingAs($this->admin)->post($route, [
            'cnic_doc' => UploadedFile::fake()->create('cnic.pdf', 4096, 'application/pdf'),
            'degree_doc' => UploadedFile::fake()->create('degree.pdf', 4096, 'application/pdf'),
        ])->assertSessionDoesntHaveErrors(['cnic_doc', 'degree_doc']);

        $this->actingAs($this->admin)->post($route, [
            'cnic_doc' => UploadedFile::fake()->create('cnic.pdf', 6144, 'application/pdf'),
        ])->assertSessionHasErrors('cnic_doc');

        // None of the three takes an archive; the mimes lists decide that.
        $this->actingAs($this->admin)->post($route, ['degree_doc' => $this->realZip(64)])
            ->assertSessionHasErrors('degree_doc');
    }

    public function test_the_biometric_csv_is_unchanged_and_refuses_an_archive(): void
    {
        // max:5120 already matched the other-files limit, so nothing moved.
        // ~180,000 rows at the format the parser reads, which covers a year
        // for a few hundred students; larger academies import a term at a time
        // and re-importing a recorded row counts as a duplicate, not a punch.
        $device = \App\Models\BiometricDevice::create([
            'name' => 'Gate', 'serial_number' => 'SN-'.Str::random(6), 'api_key' => Str::random(32),
        ]);
        $route = route('admin.biometric.punches.import');

        $this->actingAs($this->admin)->post($route, [
            'device_id' => $device->id,
            'file' => UploadedFile::fake()->create('punches.csv', 6144, 'text/csv'),
        ])->assertSessionHasErrors('file');

        // An archive is refused by mimes:csv,txt — and nothing in this app
        // unpacks one, which is the safe position for an import that reads
        // rows straight out of the uploaded file.
        $this->actingAs($this->admin)->post($route, [
            'device_id' => $device->id,
            'file' => $this->realZip(64),
        ])->assertSessionHasErrors('file');

        $this->actingAs($this->admin)->post($route, [
            'device_id' => $device->id,
            'file' => UploadedFile::fake()->create('punches.csv', 4096, 'text/csv'),
        ])->assertSessionDoesntHaveErrors('file');
    }

    // --------------------------------------------------------------- fixtures

    protected function courseFields(string $title): array
    {
        return [
            'title' => $title,
            'excerpt' => 'x',
            'level' => 'beginner',
            'status' => 'draft',
            'category_id' => $this->category->id,
            'is_free' => 1,
        ];
    }

    protected function course(string $title = 'Policy course'): Course
    {
        return Course::create([
            ...$this->courseFields($title),
            'slug' => Str::slug($title.'-'.Str::random(4)),
            'published_at' => now()->subDay(),
        ]);
    }

    protected function lesson(): Lesson
    {
        $course = $this->course('Lesson course');
        $module = Module::create(['course_id' => $course->id, 'title' => 'M1', 'position' => 1]);

        return Lesson::create([
            'module_id' => $module->id, 'course_id' => $course->id,
            'title' => 'Lesson 1', 'type' => 'video', 'position' => 1,
        ]);
    }
}
