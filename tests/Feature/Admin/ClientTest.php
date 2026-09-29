<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The client book: admin-only, and not deletable out from under its projects.
 */
class ClientTest extends TestCase
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

    /** @param  array<string, mixed>  $overrides */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Zephyrine Quartermain',
            'company' => 'Bartleby Ironworks PLC',
            'email' => 'zq@bartleby-ironworks.test',
            'phone' => '+92-300-7654321',
            'address' => '14 Foundry Road',
            'notes' => 'Prefers email.',
            'is_active' => 1,
        ], $overrides);
    }

    protected function create(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)
            ->post(route('admin.clients.store'), $this->payload($overrides));
    }

    /** A project on this client, written straight past the controller. */
    protected function projectFor(Client $client, string $code = 'PRJ-001'): Project
    {
        $team = Team::create(['name' => 'Web '.$code, 'team_lead_id' => null, 'is_active' => true]);

        return Project::create([
            'name' => 'Website Redesign',
            'code' => $code,
            'client_id' => $client->id,
            'team_id' => $team->id,
            'project_status_id' => ProjectStatus::active()->firstOrFail()->id,
            'currency' => 'PKR',
        ]);
    }

    /* -------------------------------- Tests -------------------------------- */

    public function test_an_admin_adds_a_client(): void
    {
        $this->create()
            ->assertRedirect(route('admin.clients.index'))
            ->assertSessionHasNoErrors();

        $client = Client::firstOrFail();

        $this->assertSame('Zephyrine Quartermain', $client->name);
        $this->assertSame('Bartleby Ironworks PLC', $client->company);
        $this->assertTrue($client->is_active);
        $this->assertNull($client->user_id);
    }

    public function test_the_client_screens_render(): void
    {
        $this->create()->assertSessionHasNoErrors();
        $client = Client::firstOrFail();

        $this->actingAs($this->admin)->get(route('admin.clients.index'))
            ->assertOk()->assertSee('Bartleby Ironworks PLC');

        $this->actingAs($this->admin)->get(route('admin.clients.create'))
            ->assertOk()->assertSee('Client login');

        $this->actingAs($this->admin)->get(route('admin.clients.edit', $client))
            ->assertOk()->assertSee('Zephyrine Quartermain');

        $this->actingAs($this->admin)->get(route('admin.clients.show', $client))
            ->assertOk()->assertSee('zq@bartleby-ironworks.test');
    }

    public function test_a_client_with_projects_cannot_be_deleted(): void
    {
        $this->create()->assertSessionHasNoErrors();
        $client = Client::firstOrFail();

        $this->projectFor($client, 'PRJ-001');
        $this->projectFor($client, 'PRJ-002');

        $this->actingAs($this->admin)
            ->delete(route('admin.clients.destroy', $client))
            ->assertSessionHasErrors('client');

        // The count is in the message, so the admin knows what is in the way.
        $this->assertStringContainsString('2 project(s)', session('errors')->first('client'));
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'deleted_at' => null]);

        // Nothing was reassigned or nulled to make the delete possible.
        $this->assertSame(2, Project::where('client_id', $client->id)->count());
    }

    public function test_a_soft_deleted_project_still_blocks_the_delete(): void
    {
        $this->create()->assertSessionHasNoErrors();
        $client = Client::firstOrFail();

        $this->projectFor($client)->delete();

        $this->actingAs($this->admin)
            ->delete(route('admin.clients.destroy', $client))
            ->assertSessionHasErrors('client');

        // It still holds the foreign key, and restoring it must not bring back
        // a project belonging to a client who no longer exists.
        $this->assertStringContainsString('1 project(s)', session('errors')->first('client'));
    }

    public function test_a_client_with_no_projects_is_deleted(): void
    {
        $this->create()->assertSessionHasNoErrors();
        $client = Client::firstOrFail();

        $this->actingAs($this->admin)
            ->delete(route('admin.clients.destroy', $client))
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('clients', ['id' => $client->id]);
    }

    public function test_deactivating_a_client_keeps_their_projects(): void
    {
        $this->create()->assertSessionHasNoErrors();
        $client = Client::firstOrFail();
        $this->projectFor($client);

        $this->actingAs($this->admin)
            ->post(route('admin.clients.toggle', $client))
            ->assertSessionHasNoErrors();

        $this->assertFalse($client->fresh()->is_active);
        $this->assertSame(1, $client->projects()->count());
    }

    public function test_one_login_belongs_to_one_client(): void
    {
        $login = User::factory()->create();
        $login->assignRole('client');

        $this->create(['user_id' => $login->id])->assertSessionHasNoErrors();

        $this->create(['name' => 'Someone Else', 'user_id' => $login->id])
            ->assertSessionHasErrors('user_id');

        $this->assertSame(1, Client::count());
    }
}
