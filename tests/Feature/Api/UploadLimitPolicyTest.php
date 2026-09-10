<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\FeePlan;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The same two limits, on the three fields students post to.
 *
 *   avatar   image only        -> 1 MB
 *   file     anything          -> 5 MB, archives included
 *   receipt  images AND pdf    -> 5 MB for the whole field
 *
 * The receipt is the one that had to be decided rather than derived. Splitting
 * it — 1 MB for a JPG, 5 MB for a PDF — would be technically consistent and
 * practically hostile: the commonest receipt is a phone photo of a bank slip,
 * 2-4 MB straight off the camera, so the image half of the rule would refuse
 * most real ones while the PDF half sailed through. One limit for the field.
 */
class UploadLimitPolicyTest extends ApiTestCase
{
    /**
     * A genuine archive on disk.
     *
     * UploadedFile::fake()->create('x.zip') is an empty file wearing a name;
     * `mimes:zip` consults the guessed type too, and browsers variously call
     * an archive application/zip, application/x-zip-compressed or
     * application/octet-stream. Only a real one proves anything.
     */
    protected function realZip(int $kilobytes): UploadedFile
    {
        $dir = sys_get_temp_dir().'/api-dropzone-'.Str::random(8);
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/payload.bin', str_repeat('z', $kilobytes * 1024));

        $path = $dir.'/work.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFile($dir.'/payload.bin', 'payload.bin');
        $zip->setCompressionName('payload.bin', \ZipArchive::CM_STORE);
        $zip->close();

        return new UploadedFile($path, 'work.zip', null, null, true);
    }

    /** @return array{0: \App\Models\Course, 1: Assignment} */
    protected function makeAssignment(): array
    {
        [$course] = $this->makeCourse(1);

        return [$course, Assignment::create([
            'course_id' => $course->id,
            'title' => 'Build something',
            'description' => 'A description.',
            'instructions' => '<p>Do the thing.</p>',
            'due_at' => now()->addDays(3),
            'max_score' => 100,
        ])];
    }

    /** An open invoice this student can pay. */
    protected function openInvoiceFor(User $user): Invoice
    {
        $plan = FeePlan::create([
            'user_id' => $user->id,
            'title' => 'Advanced Web Development',
            'billing_cycle' => 'annual',
            'currency' => 'USD',
            'total_amount' => 1200.00,
            'is_active' => true,
        ]);

        return Invoice::create([
            'fee_plan_id' => $plan->id,
            'user_id' => $user->id,
            'number' => 'INV-'.Str::random(6),
            'title' => 'Installment 1',
            'amount' => 400.00,
            'currency' => 'USD',
            'status' => 'open',
            'issued_at' => now()->subDays(10),
            'due_at' => now()->addMonth(),
        ]);
    }

    public function test_a_student_can_submit_a_4_mb_zip_of_their_project(): void
    {
        Storage::fake('public');
        $user = $this->actingAsStudent();
        [$course, $assignment] = $this->makeAssignment();
        $this->enroll($user, $course);

        // No mimes list on this field, so an archive was always allowed. This
        // pins it, because adding one later would quietly forbid it.
        $this->post("/api/v1/assignments/{$assignment->id}/submissions", [
            'file' => $this->realZip(4096),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_a_6_mb_submission_is_refused(): void
    {
        Storage::fake('public');
        $user = $this->actingAsStudent();
        [$course, $assignment] = $this->makeAssignment();
        $this->enroll($user, $course);

        $this->post("/api/v1/assignments/{$assignment->id}/submissions", [
            'file' => $this->realZip(6144),
        ], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');
    }

    public function test_the_submission_file_is_still_required(): void
    {
        // 9bed5dd. Changing the size must not have loosened the obligation.
        Storage::fake('public');
        $user = $this->actingAsStudent();
        [$course, $assignment] = $this->makeAssignment();
        $this->enroll($user, $course);

        $this->postJson("/api/v1/assignments/{$assignment->id}/submissions", [
            'content' => 'no file attached',
        ])->assertJsonValidationErrors('file');
    }

    public function test_the_avatar_is_an_image_field_at_1_mb(): void
    {
        Storage::fake('public');
        $this->actingAsStudent();

        $this->postJson('/api/v1/auth/avatar', [
            'avatar' => UploadedFile::fake()->create('me.jpg', 1536, 'image/jpeg'),
        ])->assertJsonValidationErrors('avatar');

        $this->post('/api/v1/auth/avatar', [
            'avatar' => UploadedFile::fake()->create('me.jpg', 900, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertOk();
    }

    public function test_an_archive_is_refused_as_an_avatar(): void
    {
        // `image` does this — no mimes list needed and none added.
        Storage::fake('public');
        $this->actingAsStudent();

        $this->post('/api/v1/auth/avatar', ['avatar' => $this->realZip(64)], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('avatar');
    }

    public function test_the_fee_receipt_gives_a_photo_and_a_pdf_the_same_5_mb(): void
    {
        Storage::fake('public');
        $user = $this->actingAsStudent();
        $invoice = $this->openInvoiceFor($user);

        // The whole point of the decision: a 4 MB phone photo is fine here.
        $this->post("/api/v1/billing/invoices/{$invoice->id}/submissions", [
            'channel' => 'jazzcash',
            'payment_date' => now()->toDateString(),
            'receipt' => UploadedFile::fake()->create('slip.jpg', 4096, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $this->postJson("/api/v1/billing/invoices/{$invoice->id}/submissions", [
            'channel' => 'jazzcash',
            'payment_date' => now()->toDateString(),
            'receipt' => UploadedFile::fake()->create('slip.jpg', 6144, 'image/jpeg'),
        ])->assertJsonValidationErrors('receipt');
    }

    public function test_the_fee_receipt_refuses_an_archive(): void
    {
        // A receipt is a document you look at, not a bundle. The mimes list
        // decides it, and that list is unchanged.
        Storage::fake('public');
        $user = $this->actingAsStudent();
        $invoice = $this->openInvoiceFor($user);

        $this->postJson("/api/v1/billing/invoices/{$invoice->id}/submissions", [
            'channel' => 'jazzcash',
            'payment_date' => now()->toDateString(),
            'receipt' => $this->realZip(64),
        ])->assertJsonValidationErrors('receipt');
    }
}
