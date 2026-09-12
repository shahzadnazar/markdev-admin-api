<?php

namespace Tests\Feature;

use App\Support\PrivateFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Moving the files that were already on the public disk.
 *
 * The stored paths do not change — the same relative path names the file on
 * either disk — which is what makes this safe to interrupt and safe to re-run.
 * These pin that property, because the alternative (rewriting every path as it
 * moves) is the version that cannot survive being killed half way.
 */
class PrivatiseUploadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake(PrivateFiles::DISK);
    }

    public function test_it_moves_private_kinds_and_deletes_the_public_copy(): void
    {
        Storage::disk('public')->put('students/documents/cnic.pdf', 'SECRET');
        Storage::disk('public')->put('submissions/work.pdf', 'HOMEWORK');

        $this->artisan('files:privatise')->assertSuccessful();

        foreach (['students/documents/cnic.pdf' => 'SECRET', 'submissions/work.pdf' => 'HOMEWORK'] as $path => $body) {
            $this->assertSame($body, Storage::disk(PrivateFiles::DISK)->get($path), 'the bytes moved intact');
            $this->assertFalse(Storage::disk('public')->exists($path), 'the public copy is gone');
        }
    }

    public function test_public_kinds_are_left_where_they_are(): void
    {
        // Thumbnails render in an <img> the portal cannot authenticate.
        Storage::disk('public')->put('courses/thumb.png', 'THUMB');
        Storage::disk('public')->put('avatars/me.png', 'AVATAR');

        $this->artisan('files:privatise')->assertSuccessful();

        $this->assertTrue(Storage::disk('public')->exists('courses/thumb.png'));
        $this->assertTrue(Storage::disk('public')->exists('avatars/me.png'));
        $this->assertFalse(Storage::disk(PrivateFiles::DISK)->exists('courses/thumb.png'));
    }

    public function test_it_is_re_runnable_with_no_duplicates_and_no_damage(): void
    {
        Storage::disk('public')->put('notes/week-1.pdf', 'SLIDES');

        $this->artisan('files:privatise')->assertSuccessful();
        $this->artisan('files:privatise')->assertSuccessful();
        $this->artisan('files:privatise')->assertSuccessful();

        $this->assertSame(['notes/week-1.pdf'], Storage::disk(PrivateFiles::DISK)->allFiles('notes'));
        $this->assertSame('SLIDES', Storage::disk(PrivateFiles::DISK)->get('notes/week-1.pdf'));
        $this->assertSame([], Storage::disk('public')->allFiles('notes'));
    }

    /**
     * The interrupted case: bytes copied, public original not yet deleted.
     *
     * This is what a killed run leaves behind, and it is the state a re-run has
     * to recognise rather than treat as a fresh file to copy over the top.
     */
    public function test_a_half_finished_move_is_completed_not_repeated(): void
    {
        Storage::disk('public')->put('receipts/r1.pdf', 'RECEIPT');
        Storage::disk(PrivateFiles::DISK)->put('receipts/r1.pdf', 'RECEIPT');

        $this->artisan('files:privatise')->assertSuccessful();

        $this->assertSame('RECEIPT', Storage::disk(PrivateFiles::DISK)->get('receipts/r1.pdf'));
        $this->assertFalse(Storage::disk('public')->exists('receipts/r1.pdf'));
    }

    /**
     * A differing private copy is overwritten from the public original, not
     * assumed done — the public disk is the source of truth until it is gone.
     */
    public function test_a_mismatched_private_copy_is_replaced(): void
    {
        Storage::disk('public')->put('receipts/r1.pdf', 'THE-REAL-ONE');
        Storage::disk(PrivateFiles::DISK)->put('receipts/r1.pdf', 'TRUNCATED');

        $this->artisan('files:privatise')->assertSuccessful();

        $this->assertSame('THE-REAL-ONE', Storage::disk(PrivateFiles::DISK)->get('receipts/r1.pdf'));
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        Storage::disk('public')->put('students/photos/face.png', 'PHOTO');

        $this->artisan('files:privatise', ['--dry-run' => true])->assertSuccessful();

        $this->assertTrue(Storage::disk('public')->exists('students/photos/face.png'));
        $this->assertFalse(Storage::disk(PrivateFiles::DISK)->exists('students/photos/face.png'));
    }

    public function test_keep_public_copies_and_verifies_without_deleting(): void
    {
        // For a cautious first pass in production: move, check, delete later.
        Storage::disk('public')->put('submissions/work.pdf', 'HOMEWORK');

        $this->artisan('files:privatise', ['--keep-public' => true])->assertSuccessful();

        $this->assertSame('HOMEWORK', Storage::disk(PrivateFiles::DISK)->get('submissions/work.pdf'));
        $this->assertTrue(Storage::disk('public')->exists('submissions/work.pdf'), 'kept on request');
    }

    public function test_a_path_with_no_file_behind_it_is_reported_not_fatal(): void
    {
        $course = \App\Models\Course::create([
            'title' => 'C', 'slug' => 'c-'.uniqid(), 'excerpt' => 'x', 'level' => 'beginner',
            'status' => 'published', 'published_at' => now(), 'is_free' => true,
        ]);
        $course->resources()->create([
            'name' => 'Gone', 'kind' => 'file', 'file_path' => 'resources/gone.pdf',
            'file_type' => 'pdf', 'size_bytes' => 1,
        ]);
        Storage::disk('public')->put('notes/here.pdf', 'HERE');

        // Reported, and the run still succeeds and still moves everything else.
        $this->artisan('files:privatise')
            ->expectsOutputToContain('no file behind LessonResource')
            ->assertSuccessful();

        $this->assertSame('HERE', Storage::disk(PrivateFiles::DISK)->get('notes/here.pdf'));
    }
}
