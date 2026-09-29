<?php

namespace Tests\Feature\Admin;

use App\Models\Project;
use App\Models\TeamFile;
use App\Models\User;
use App\Support\PrivateFiles;
use App\Support\UploadLimits;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\BuildsTeamPortal;
use Tests\TestCase;

/**
 * Files on a project or a task: the limits, the private disk, and the fact
 * that a signature proves identity and never permission.
 */
class TeamFileTest extends TestCase
{
    use BuildsTeamPortal, RefreshDatabase;

    protected User $admin;

    protected User $lead;

    protected User $member;

    protected User $stranger;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(PrivateFiles::DISK);
        Storage::fake('public');

        $this->seed(RolePermissionSeeder::class);
        $this->freezeOnMonday();

        $this->admin = $this->roleUser('super-admin');
        $this->lead = $this->roleUser('team-lead', ['name' => 'Ayesha Khan']);
        $this->member = $this->roleUser('team', ['name' => 'Bilal Ahmed']);
        $this->stranger = $this->roleUser('team-lead', ['name' => 'Chandni Rao']);

        $team = $this->makeTeam('Web', $this->lead, [$this->lead, $this->member]);
        $this->makeTeam('Graphics', $this->stranger, [$this->stranger]);

        $this->project = $this->makeProject($team);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function upload(User $as, UploadedFile $file)
    {
        return $this->actingAs($as)->post(
            route('admin.team-files.store', ['project', $this->project->id]),
            ['file' => $file],
        );
    }

    /**
     * The size a user could REALLY send: the rule or php.ini, whichever bites.
     *
     * Derived from UploadLimits rather than typed here, so a php.ini change
     * moves this test's idea of the ceiling with it instead of leaving the test
     * asserting something the server would refuse.
     */
    protected function sendableKb(int $ruleKb): int
    {
        return (int) floor(UploadLimits::maxBytes($ruleKb) / 1024);
    }

    /* -------------------------------- Limits -------------------------------- */

    /**
     * What the VALIDATOR refuses, and what the UI is allowed to promise.
     *
     * Two different questions, and both are asked against UploadLimits rather
     * than a number typed here. The validator is the only thing a test can make
     * refuse — PHP rejects an oversized upload before Laravel ever sees it, so
     * a fake upload never meets that limit — which is precisely why the chip
     * must not advertise the rule when php.ini is stricter. The second
     * assertion is that it does not.
     */
    public function test_an_image_at_the_sendable_ceiling_is_accepted_and_one_over_the_rule_is_refused(): void
    {
        $sendable = $this->sendableKb(TeamFile::IMAGE_MAX_KB);

        $this->upload($this->member, UploadedFile::fake()->create('shot.png', $sendable, 'image/png'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, TeamFile::count());

        // Over the image ceiling, refused by the controller's own check — the
        // rule on the field is the looser file limit, so this is the half that
        // makes an image tighter than a document.
        $this->upload($this->member, UploadedFile::fake()->create('big.png', $sendable + 64, 'image/png'))
            ->assertSessionHasErrors('file');

        $this->assertSame(1, TeamFile::count());

        // And the chip cannot promise more than the server will take.
        $this->assertLessThanOrEqual(
            TeamFile::IMAGE_MAX_KB * 1024,
            UploadLimits::maxBytes(TeamFile::IMAGE_MAX_KB),
            'the image ceiling advertises more than the rule allows',
        );
    }

    public function test_a_document_at_the_sendable_ceiling_is_accepted_and_one_over_the_rule_is_refused(): void
    {
        $sendable = $this->sendableKb(TeamFile::FILE_MAX_KB);

        $this->upload($this->member, UploadedFile::fake()->create('brief.zip', $sendable, 'application/zip'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, TeamFile::count());

        // Over the RULE, which is what the validator can actually refuse.
        $this->upload($this->member, UploadedFile::fake()->create('huge.zip', TeamFile::FILE_MAX_KB + 1024, 'application/zip'))
            ->assertSessionHasErrors('file');

        $this->assertSame(1, TeamFile::count());

        $this->assertLessThanOrEqual(
            TeamFile::FILE_MAX_KB * 1024,
            UploadLimits::maxBytes(TeamFile::FILE_MAX_KB),
            'the file ceiling advertises more than the rule allows',
        );
    }

    /**
     * An image is held to the tighter of the two ceilings.
     *
     * A zip of a size an image would be refused at is fine, which is the whole
     * reason there are two numbers rather than one.
     */
    public function test_the_image_ceiling_is_tighter_than_the_file_one(): void
    {
        $this->assertLessThan(TeamFile::FILE_MAX_KB, TeamFile::IMAGE_MAX_KB);

        $overImage = $this->sendableKb(TeamFile::IMAGE_MAX_KB) + 64;

        $this->upload($this->member, UploadedFile::fake()->create('archive.zip', $overImage, 'application/zip'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, TeamFile::count());
    }

    /* ------------------------------ The disk -------------------------------- */

    public function test_no_file_lands_on_the_public_disk(): void
    {
        $this->upload($this->member, UploadedFile::fake()->create('brief.pdf', 10, 'application/pdf'))
            ->assertSessionHasNoErrors();

        $file = TeamFile::firstOrFail();

        // Under a PRIVATE_PREFIXES entry, on the private disk, and nowhere on
        // the public one. A student's photograph was once served to anyone who
        // guessed the URL; nothing here repeats it.
        $this->assertTrue(PrivateFiles::isPrivatePath($file->path), "{$file->path} is not under a private prefix");
        $this->assertStringStartsWith(TeamFile::PREFIX.'/', $file->path);
        Storage::disk(PrivateFiles::DISK)->assertExists($file->path);
        Storage::disk('public')->assertMissing($file->path);
    }

    public function test_the_stored_file_survives_a_soft_delete(): void
    {
        $this->upload($this->member, UploadedFile::fake()->create('brief.pdf', 10, 'application/pdf'));
        $file = TeamFile::firstOrFail();

        $this->actingAs($this->member)->delete(route('admin.team-files.destroy', $file))
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('team_files', ['id' => $file->id]);
        // A restored row whose bytes are gone is worse than one that takes
        // disk space.
        Storage::disk(PrivateFiles::DISK)->assertExists($file->path);
    }

    /* ---------------------- A signature is not permission ------------------- */

    /**
     * A link signed for one person does not serve another.
     *
     * The signature carries WHO is asking across a hop that cannot carry a
     * token. It has never been the authorisation, and the controller still asks
     * whether that person can see the work.
     */
    public function test_a_url_signed_for_one_user_does_not_serve_another(): void
    {
        $this->upload($this->member, UploadedFile::fake()->create('brief.pdf', 10, 'application/pdf'));
        $file = TeamFile::firstOrFail();

        $signedForMember = PrivateFiles::signedUrl('files.team', ['file' => $file->id], $this->member);
        $signedForStranger = PrivateFiles::signedUrl('files.team', ['file' => $file->id], $this->stranger);

        // The session from the upload above has to go, or ResolveFileViewer
        // short-circuits on it and the signature is never what identifies the
        // caller — which would make this test pass while proving nothing.
        $this->app['auth']->forgetGuards();

        // Valid signature, right person: served.
        $this->get($signedForMember)->assertOk();

        $this->app['auth']->forgetGuards();

        // The same link shape, still perfectly valid — swapping `u` by hand
        // would break the signature, so this mints a genuine one for somebody
        // who cannot see this project. It is still refused, because the
        // signature says WHO is asking and never that they may look.
        $this->assertTrue(URL::hasValidSignature($this->makeRequest($signedForStranger)));
        $this->get($signedForStranger)->assertNotFound();
    }

    public function test_a_stranger_with_a_session_is_refused_too(): void
    {
        $this->upload($this->member, UploadedFile::fake()->create('brief.pdf', 10, 'application/pdf'));
        $file = TeamFile::firstOrFail();

        $this->actingAs($this->stranger)->get(route('files.team', $file))->assertNotFound();
        $this->actingAs($this->lead)->get(route('files.team', $file))->assertOk();
    }

    /** A request object for the signature check above. */
    protected function makeRequest(string $url): Request
    {
        return Request::create($url);
    }

    /* --------------------------- Client visibility -------------------------- */

    public function test_only_an_admin_may_decide_what_a_client_sees(): void
    {
        $this->upload($this->member, UploadedFile::fake()->create('brief.pdf', 10, 'application/pdf'));
        $file = TeamFile::firstOrFail();

        // Never on upload, whoever uploads it.
        $this->assertFalse($file->is_client_visible);

        foreach ([$this->member, $this->lead] as $user) {
            $this->actingAs($user)
                ->post(route('admin.team-files.visibility', $file), ['is_client_visible' => '1'])
                ->assertForbidden();
        }

        $this->assertFalse($file->fresh()->is_client_visible);

        // This matches milestones, whose routes are already admin-only, so the
        // two answers about what leaves the building agree.
        $this->actingAs($this->admin)
            ->post(route('admin.team-files.visibility', $file), ['is_client_visible' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($file->fresh()->is_client_visible);
    }

    /* -------------------------------- Deleting ------------------------------ */

    public function test_the_uploader_or_an_admin_may_delete_and_nobody_else(): void
    {
        $this->upload($this->member, UploadedFile::fake()->create('brief.pdf', 10, 'application/pdf'));
        $file = TeamFile::firstOrFail();

        // A lead can see the work and did not upload it: not theirs to remove.
        $this->actingAs($this->lead)->delete(route('admin.team-files.destroy', $file))->assertForbidden();

        $this->actingAs($this->member)->delete(route('admin.team-files.destroy', $file))
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('team_files', ['id' => $file->id]);
    }

    public function test_somebody_outside_the_work_cannot_upload_to_it(): void
    {
        $this->actingAs($this->stranger)
            ->post(route('admin.team-files.store', ['project', $this->project->id]), [
                'file' => UploadedFile::fake()->create('theirs.pdf', 10, 'application/pdf'),
            ])
            ->assertNotFound();

        $this->assertSame(0, TeamFile::count());
    }
}
