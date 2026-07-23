<?php

namespace Tests\Feature\Admin;

use App\Models\Course;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentModuleTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('public');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Hamza Tariq',
            'father_name' => 'Tariq Mehmood',
            'date_of_birth' => '2004-03-15',
            'gender' => 'male',
            'address' => 'Street 4, Model Town, Bahawalpur',
            'contact_number' => '0301 2345678',
            'cnic' => '31202-7654321-3',
            'guardian_contact' => '0300 1112223',
            'current_qualification' => 'Intermediate (ICS)',
            'applied_course' => 'Advanced Web Development',
            'email' => 'hamza@student.test',
            'emergency_name' => 'Tariq Mehmood',
            'emergency_contact' => '0300 1112223',
            'emergency_relation' => 'Father',
            'emergency_residence' => 'Bahawalpur',
            'date_of_joining' => now()->toDateString(),
            'registration_fee' => 2000,
            'submitted_fee' => 5000,
            'terms' => '1',
            'photo' => UploadedFile::fake()->image('photo.jpg', 400, 400)->size(300),
            'cnic_doc' => UploadedFile::fake()->create('cnic.pdf', 500, 'application/pdf'),
            'degree_doc' => UploadedFile::fake()->image('degree.png', 800, 600)->size(700),
        ], $overrides);
    }

    public function test_admin_can_register_a_student_with_documents(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/students', $this->payload());

        $student = User::where('email', 'hamza@student.test')->first();

        $this->assertNotNull($student);
        $this->assertTrue($student->hasRole('student'));
        $response->assertRedirect(route('admin.students.show', $student));

        $profile = $student->studentProfile;
        $this->assertNotNull($profile);
        $this->assertSame('Tariq Mehmood', $profile->father_name);
        $this->assertSame('31202-7654321-3', $profile->cnic);
        $this->assertStringStartsWith('MD-'.now()->year.'-', $profile->reg_no);
        $this->assertNotNull($profile->terms_accepted_at);
        $this->assertSame($this->admin->id, $profile->registered_by);

        Storage::disk('public')->assertExists($profile->photo_path);
        Storage::disk('public')->assertExists($profile->cnic_doc_path);
        Storage::disk('public')->assertExists($profile->degree_doc_path);

        // Photo doubles as the account avatar.
        $this->assertSame($profile->photo_path, $student->fresh()->avatar_path);
    }

    public function test_documents_over_one_megabyte_are_rejected(): void
    {
        $response = $this->actingAs($this->admin)
            ->from('/admin/students/register')
            ->post('/admin/students', $this->payload([
                'photo' => UploadedFile::fake()->image('big.jpg')->size(1500),
                'cnic_doc' => UploadedFile::fake()->create('big.pdf', 2048, 'application/pdf'),
            ]));

        $response->assertSessionHasErrors(['photo', 'cnic_doc']);
        $this->assertNull(User::where('email', 'hamza@student.test')->first());
    }

    public function test_documents_are_required_on_registration(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/admin/students', $this->payload(['photo' => null, 'cnic_doc' => null, 'degree_doc' => null]));

        $response->assertSessionHasErrors(['photo', 'cnic_doc', 'degree_doc']);
    }

    public function test_terms_must_be_accepted(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/admin/students', $this->payload(['terms' => null]));

        $response->assertSessionHasErrors('terms');
    }

    public function test_duplicate_cnic_is_rejected(): void
    {
        $this->actingAs($this->admin)->post('/admin/students', $this->payload());

        $response = $this->actingAs($this->admin)->post('/admin/students', $this->payload([
            'email' => 'other@student.test',
        ]));

        $response->assertSessionHasErrors('cnic');
    }

    public function test_registration_can_enroll_and_create_installment_plan(): void
    {
        $course = Course::create([
            'title' => 'Laravel Mastery',
            'slug' => 'laravel-mastery-'.Str::random(4),
            'excerpt' => 'x',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now(),
            'is_free' => false,
        ]);

        $this->actingAs($this->admin)->post('/admin/students', $this->payload([
            'course_id' => $course->id,
            'create_plan' => '1',
            'total_fee' => 60000,
            'months' => 6,
            'due_day' => 5,
        ]));

        $student = User::where('email', 'hamza@student.test')->first();

        $this->assertDatabaseHas('enrollments', ['user_id' => $student->id, 'course_id' => $course->id]);
        $this->assertDatabaseHas('fee_plans', ['user_id' => $student->id, 'course_id' => $course->id]);
        $this->assertSame(6, $student->invoices()->count());
        $this->assertEquals(60000.0, (float) $student->invoices()->sum('amount'));
    }

    public function test_directory_lists_students_with_reg_no(): void
    {
        $this->actingAs($this->admin)->post('/admin/students', $this->payload());

        $this->actingAs($this->admin)->get('/admin/students')
            ->assertOk()
            ->assertSee('Student management')
            ->assertSee('Hamza Tariq')
            ->assertSee('MD-'.now()->year.'-0001');
    }

    public function test_profile_page_shows_documents_and_admission_record(): void
    {
        $this->actingAs($this->admin)->post('/admin/students', $this->payload());
        $student = User::where('email', 'hamza@student.test')->first();

        $this->actingAs($this->admin)->get("/admin/students/{$student->id}")
            ->assertOk()
            ->assertSee('Documents')
            ->assertSee('CNIC / B-Form copy')
            ->assertSee('Tariq Mehmood')
            ->assertSee('Emergency contact');
    }

    public function test_students_are_hidden_from_the_users_screen(): void
    {
        $this->actingAs($this->admin)->post('/admin/students', $this->payload());

        $this->actingAs($this->admin)->get('/admin/users')
            ->assertOk()
            ->assertDontSee('Hamza Tariq');
    }

    public function test_instructor_cannot_access_student_management(): void
    {
        $instructor = User::factory()->create();
        $instructor->assignRole('instructor');

        $this->actingAs($instructor)->get('/admin/students')->assertForbidden();
        $this->actingAs($instructor)->post('/admin/students', $this->payload())->assertForbidden();
    }

    public function test_non_student_ids_404_on_the_profile_page(): void
    {
        $this->actingAs($this->admin)
            ->get("/admin/students/{$this->admin->id}")
            ->assertNotFound();
    }

    public function test_editing_replaces_documents_and_updates_profile(): void
    {
        $this->actingAs($this->admin)->post('/admin/students', $this->payload());
        $student = User::where('email', 'hamza@student.test')->first();
        $oldPhoto = $student->studentProfile->photo_path;

        $response = $this->actingAs($this->admin)->put("/admin/students/{$student->id}", $this->payload([
            'name' => 'Hamza T. Updated',
            'photo' => UploadedFile::fake()->image('new.jpg', 300, 300)->size(200),
            'cnic_doc' => null,
            'degree_doc' => null,
            'terms' => null,
        ]));

        $response->assertRedirect(route('admin.students.show', $student));

        $student->refresh();
        $this->assertSame('Hamza T. Updated', $student->name);
        $this->assertNotSame($oldPhoto, $student->studentProfile->photo_path);
        Storage::disk('public')->assertMissing($oldPhoto);
        Storage::disk('public')->assertExists($student->studentProfile->photo_path);
    }

    public function test_reg_numbers_increment_sequentially(): void
    {
        $this->actingAs($this->admin)->post('/admin/students', $this->payload());
        $this->actingAs($this->admin)->post('/admin/students', $this->payload([
            'email' => 'second@student.test',
            'cnic' => '31202-9998887-5',
        ]));

        $this->assertSame(
            ['MD-'.now()->year.'-0001', 'MD-'.now()->year.'-0002'],
            StudentProfile::orderBy('id')->pluck('reg_no')->all(),
        );
    }
}
