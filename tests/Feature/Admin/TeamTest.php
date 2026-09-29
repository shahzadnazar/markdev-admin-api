<?php

namespace Tests\Feature\Admin;

use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Teams: the rules that make a team a team.
 *
 * One lead, who is on the team. A person on as many teams as they work for.
 * A name that is unique among live teams and free again once one is deleted.
 */
class TeamTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $alice;

    protected User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');

        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
    }

    /* ------------------------------- Helpers ------------------------------- */

    /** @param  array<string, mixed>  $overrides */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Web',
            'members' => [$this->alice->id, $this->bob->id],
            'team_lead_id' => $this->alice->id,
            'is_active' => 1,
        ], $overrides);
    }

    protected function create(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)
            ->post(route('admin.teams.store'), $this->payload($overrides));
    }

    /* -------------------------------- Tests -------------------------------- */

    public function test_a_team_whose_lead_is_a_member_is_created(): void
    {
        $this->create()->assertRedirect(route('admin.teams.index'))->assertSessionHasNoErrors();

        $team = Team::first();

        $this->assertSame('Web', $team->name);
        $this->assertSame($this->alice->id, $team->team_lead_id);
        $this->assertSame(2, $team->members()->count());
        $this->assertTrue($team->leadIsMember());
    }

    public function test_a_lead_who_is_not_a_member_is_refused(): void
    {
        $outsider = User::factory()->create();

        $this->create(['team_lead_id' => $outsider->id])
            ->assertSessionHasErrors('team_lead_id');

        $this->assertSame(0, Team::count());
    }

    public function test_a_duplicate_name_in_another_case_is_refused(): void
    {
        $this->create()->assertSessionHasNoErrors();

        // Different case AND different spacing: both fold to the same name_key.
        $this->create(['name' => ' web '])->assertSessionHasErrors('name');

        $this->assertSame(1, Team::count());
    }

    public function test_a_soft_deleted_teams_name_can_be_used_again(): void
    {
        $this->create()->assertSessionHasNoErrors();

        $team = Team::first();
        $this->actingAs($this->admin)->delete(route('admin.teams.destroy', $team))
            ->assertSessionHasNoErrors();

        // The unique index is on name_key, which the delete hook clears — an
        // index on `name` alone would have reserved "Web" for ever.
        $this->assertNull(Team::withTrashed()->find($team->id)->name_key);

        $this->create(['name' => 'Web'])->assertSessionHasNoErrors();

        $this->assertSame(1, Team::count());
        $this->assertSame(2, Team::withTrashed()->count());
    }

    public function test_a_member_may_belong_to_several_teams(): void
    {
        $this->create(['name' => 'Web'])->assertSessionHasNoErrors();
        $this->create(['name' => 'Mobile', 'team_lead_id' => $this->bob->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->bob->teams()->count());
        $this->assertSame(['Mobile', 'Web'], $this->bob->teams()->orderBy('name')->pluck('name')->all());
    }

    public function test_deactivating_a_user_keeps_their_memberships(): void
    {
        $this->create()->assertSessionHasNoErrors();
        $team = Team::first();

        $this->bob->update(['is_active' => false]);

        $this->assertSame(2, $team->members()->count());
        $this->assertDatabaseHas('team_members', ['team_id' => $team->id, 'user_id' => $this->bob->id]);

        // Soft-deleting the account keeps the row too: the work they did still
        // belongs to this team. Only erasing the account cascades it away.
        $this->bob->delete();

        $this->assertDatabaseHas('team_members', ['team_id' => $team->id, 'user_id' => $this->bob->id]);
    }

    public function test_deactivating_a_team_keeps_its_members_and_its_lead(): void
    {
        $this->create()->assertSessionHasNoErrors();
        $team = Team::first();

        $this->actingAs($this->admin)->post(route('admin.teams.toggle', $team))
            ->assertSessionHasNoErrors();

        $team->refresh();

        $this->assertFalse($team->is_active);
        $this->assertSame(2, $team->members()->count());
        $this->assertSame($this->alice->id, $team->team_lead_id);
    }

    public function test_the_unique_index_is_the_race_backstop(): void
    {
        $this->create()->assertSessionHasNoErrors();

        // Straight past the controller, as two simultaneous requests would
        // arrive: the database has to refuse the second one on its own.
        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('teams')->insert([
            'name' => 'WEB',
            'name_key' => Team::normaliseName('WEB'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_team_with_no_members_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.teams.store'), [
                'name' => 'Empty',
                'team_lead_id' => $this->alice->id,
                'is_active' => 1,
            ])
            ->assertSessionHasErrors('members');

        $this->assertSame(0, Team::count());
    }

    public function test_the_team_screens_render(): void
    {
        $this->create()->assertSessionHasNoErrors();
        $team = Team::first();

        $this->actingAs($this->admin)->get(route('admin.teams.index'))
            ->assertOk()->assertSee('Web')->assertSee('Alice');

        $this->actingAs($this->admin)->get(route('admin.teams.create'))
            ->assertOk()->assertSee('Team lead');

        $this->actingAs($this->admin)->get(route('admin.teams.edit', $team))
            ->assertOk()->assertSee('Edit Web');
    }

    public function test_editing_a_team_may_remove_a_member_and_change_the_lead(): void
    {
        $this->create()->assertSessionHasNoErrors();
        $team = Team::first();

        $this->actingAs($this->admin)
            ->put(route('admin.teams.update', $team), $this->payload([
                'members' => [$this->bob->id],
                'team_lead_id' => $this->bob->id,
            ]))
            ->assertSessionHasNoErrors();

        $team->refresh();

        $this->assertSame([$this->bob->id], $team->members()->pluck('users.id')->all());
        $this->assertSame($this->bob->id, $team->team_lead_id);
    }
}
