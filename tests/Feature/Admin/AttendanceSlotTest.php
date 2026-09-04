<?php

namespace Tests\Feature\Admin;

use App\Models\AttendanceSlot;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AttendanceSlotTest extends TestCase
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

    /* ------------------------------- Helpers ------------------------------- */

    /** A slot in the table, written straight past the controller. */
    protected function existing(string $name, string $start, string $end, array $overrides = []): AttendanceSlot
    {
        return AttendanceSlot::create(array_merge([
            'name' => $name,
            'start_time' => $start,
            'end_time' => $end,
            'days' => [1, 2, 3, 4, 5, 6, 7],
            'late_after_minutes' => 15,
            'is_active' => true,
            'sort_order' => 1,
        ], $overrides));
    }

    /** @param  array<string, mixed>  $overrides */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Afternoon',
            'days' => [1, 2, 3, 4, 5, 6, 7],
            'start_time_hour' => 9,
            'start_time_minute' => 0,
            'start_time_meridiem' => 'AM',
            'end_time_hour' => 11,
            'end_time_minute' => 0,
            'end_time_meridiem' => 'AM',
            'late_after_minutes' => 15,
            'is_active' => 1,
        ], $overrides);
    }

    /** Times as the 12-hour form posts them, from "9:00 AM" style strings. */
    protected function at(string $start, string $end): array
    {
        [$startHour, $startMinute, $startMeridiem] = $this->parts($start);
        [$endHour, $endMinute, $endMeridiem] = $this->parts($end);

        return [
            'start_time_hour' => $startHour,
            'start_time_minute' => $startMinute,
            'start_time_meridiem' => $startMeridiem,
            'end_time_hour' => $endHour,
            'end_time_minute' => $endMinute,
            'end_time_meridiem' => $endMeridiem,
        ];
    }

    /** @return array{int, int, string} */
    protected function parts(string $time): array
    {
        $parsed = \Illuminate\Support\Carbon::createFromFormat('g:i A', $time);

        return [(int) $parsed->format('g'), (int) $parsed->format('i'), $parsed->format('A')];
    }

    protected function create(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)
            ->post(route('admin.attendance-slots.store'), $this->payload($overrides));
    }

    protected function save(AttendanceSlot $slot, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)
            ->put(route('admin.attendance-slots.update', $slot), $this->payload($overrides));
    }

    /* ------------------------------ Overlapping ----------------------------- */

    public function test_a_slot_starting_when_another_ends_is_allowed(): void
    {
        $this->existing('Morning', '09:00:00', '11:00:00');

        $this->create(array_merge(['name' => 'Midday'], $this->at('11:00 AM', '1:00 PM')))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.attendance-slots.index'));

        $this->assertDatabaseHas('attendance_slots', ['name' => 'Midday', 'start_time' => '11:00:00']);
    }

    public function test_a_slot_starting_inside_another_is_rejected(): void
    {
        $this->existing('Morning', '08:00:00', '11:00:00');

        $this->create(array_merge(['name' => 'Midday'], $this->at('10:00 AM', '1:00 PM')))
            ->assertSessionHasErrors(['start_time' => 'Overlaps "Morning (8:00 AM – 11:00 AM)" on Every day. Slots cannot share the same time on the same day.']);

        $this->assertDatabaseMissing('attendance_slots', ['name' => 'Midday']);
    }

    public function test_a_slot_contained_by_another_is_rejected(): void
    {
        $this->existing('Morning', '09:00:00', '11:00:00');

        $this->create(array_merge(['name' => 'Short'], $this->at('9:30 AM', '10:30 AM')))
            ->assertSessionHasErrors('start_time');

        $this->assertDatabaseMissing('attendance_slots', ['name' => 'Short']);
    }

    public function test_a_slot_swallowing_another_is_rejected(): void
    {
        $this->existing('Morning', '09:00:00', '11:00:00');

        $this->create(array_merge(['name' => 'Long'], $this->at('8:00 AM', '12:00 PM')))
            ->assertSessionHasErrors('start_time');

        $this->assertDatabaseMissing('attendance_slots', ['name' => 'Long']);
    }

    public function test_an_identical_slot_is_rejected(): void
    {
        $this->existing('Morning', '09:00:00', '11:00:00');

        $this->create(array_merge(['name' => 'Copy'], $this->at('9:00 AM', '11:00 AM')))
            ->assertSessionHasErrors('start_time');

        $this->assertDatabaseMissing('attendance_slots', ['name' => 'Copy']);
    }

    public function test_saving_a_slot_unchanged_does_not_collide_with_itself(): void
    {
        $slot = $this->existing('Morning', '09:00:00', '11:00:00');

        $this->save($slot, array_merge(['name' => 'Morning'], $this->at('9:00 AM', '11:00 AM')))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.attendance-slots.index'));

        $this->assertSame('09:00:00', $slot->fresh()->start_time);
    }

    public function test_an_inactive_slot_still_blocks_an_overlap(): void
    {
        // Students stay assigned to a deactivated slot, so its lateness rule
        // is still deciding their attendance.
        $this->existing('Morning', '09:00:00', '11:00:00', ['is_active' => false]);

        $this->create(array_merge(['name' => 'Midday'], $this->at('10:00 AM', '12:00 PM')))
            ->assertSessionHasErrors('start_time');

        $this->assertDatabaseMissing('attendance_slots', ['name' => 'Midday']);
    }

    public function test_a_soft_deleted_slot_does_not_block_an_overlap(): void
    {
        $this->existing('Morning', '09:00:00', '11:00:00')->delete();

        $this->create(array_merge(['name' => 'Midday'], $this->at('10:00 AM', '12:00 PM')))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('attendance_slots', ['name' => 'Midday', 'deleted_at' => null]);
    }

    /* -------------------------------- Naming -------------------------------- */

    public function test_a_name_differing_only_in_case_is_rejected(): void
    {
        $this->existing('Morning', '09:00:00', '11:00:00');

        $this->create(array_merge(['name' => 'morning'], $this->at('2:00 PM', '4:00 PM')))
            ->assertSessionHasErrors(['name' => 'A slot named "morning" already exists.']);

        $this->assertSame(1, AttendanceSlot::count());
    }

    public function test_a_name_differing_only_in_surrounding_space_is_rejected(): void
    {
        $this->existing('Morning', '09:00:00', '11:00:00');

        $this->create(array_merge(['name' => '  Morning  '], $this->at('2:00 PM', '4:00 PM')))
            ->assertSessionHasErrors('name');

        $this->assertSame(1, AttendanceSlot::count());
    }

    /**
     * Not on the spec's list, but the unique index makes it a real risk: an
     * index on the raw name would keep a deleted slot's name reserved forever.
     */
    public function test_a_deleted_slot_frees_its_name(): void
    {
        $this->existing('Morning', '09:00:00', '11:00:00')->delete();

        $this->create(array_merge(['name' => 'Morning'], $this->at('9:00 AM', '11:00 AM')))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, AttendanceSlot::count());
        $this->assertSame(2, AttendanceSlot::withTrashed()->count());
    }

    /**
     * Also not on the list. Slots created before these rules existed may
     * already overlap or share a name, and those admins still need to fix the
     * grace period or deactivate a slot without first untangling the clash.
     */
    public function test_legacy_overlapping_slots_can_still_change_other_fields(): void
    {
        $this->existing('Morning', '08:00:00', '11:00:00');
        $clashing = $this->existing('Midday', '10:00:00', '13:00:00', ['sort_order' => 2]);

        $this->save($clashing, array_merge(
            ['name' => 'Midday', 'late_after_minutes' => 30],
            $this->at('10:00 AM', '1:00 PM'),
        ))->assertSessionHasNoErrors();

        $this->assertSame(30, $clashing->fresh()->late_after_minutes);

        // Moving the times, though, has to answer for the overlap.
        $this->save($clashing, array_merge(['name' => 'Midday'], $this->at('10:30 AM', '1:00 PM')))
            ->assertSessionHasErrors('start_time');
    }

    public function test_a_name_differing_only_in_inner_spacing_is_rejected(): void
    {
        $this->existing('Slot 1', '09:00:00', '11:00:00');

        $this->create(array_merge(['name' => 'Slot1'], $this->at('2:00 PM', '4:00 PM')))
            ->assertSessionHasErrors(['name' => 'A slot named "Slot1" already exists.']);

        $this->create(array_merge(['name' => 'slot  1'], $this->at('2:00 PM', '4:00 PM')))
            ->assertSessionHasErrors('name');

        $this->assertSame(1, AttendanceSlot::count());
    }

    public function test_names_that_differ_by_more_than_spacing_are_still_allowed(): void
    {
        $this->existing('Slot 1', '09:00:00', '11:00:00');

        $this->create(array_merge(['name' => 'Slot 2'], $this->at('2:00 PM', '4:00 PM')))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, AttendanceSlot::count());
    }

    /**
     * The stored key is a cache of the rule, and a cache can be stale: a row
     * written before the key column existed, or before the rule last changed,
     * or straight through the query builder. The check has to hold anyway.
     */
    public function test_a_slot_whose_stored_key_is_stale_still_blocks_a_duplicate(): void
    {
        $slot = $this->existing('slot 2', '09:00:00', '11:00:00');

        // The key the previous rule would have written -- case folded, spacing
        // left alone -- as an un-run re-key migration would leave it.
        DB::table('attendance_slots')->where('id', $slot->id)->update(['name_key' => 'slot 2']);

        $this->create(array_merge(['name' => 'slot2'], $this->at('2:00 PM', '4:00 PM')))
            ->assertSessionHasErrors('name');

        $this->assertSame(1, AttendanceSlot::count());
    }

    /* --------------------------------- Days --------------------------------- */

    public function test_the_same_hour_on_a_different_day_is_allowed(): void
    {
        $this->existing('Monday group', '09:00:00', '11:00:00', ['days' => [1]]);

        $this->create(array_merge(
            ['name' => 'Tuesday group', 'days' => [2]],
            $this->at('9:00 AM', '11:00 AM'),
        ))->assertSessionHasNoErrors();

        $this->assertSame(2, AttendanceSlot::count());
    }

    public function test_the_same_hour_on_a_shared_day_is_rejected(): void
    {
        $this->existing('Mon Wed Fri', '09:00:00', '11:00:00', ['days' => [1, 3, 5]]);

        $this->create(array_merge(
            ['name' => 'Wed Thu', 'days' => [3, 4]],
            $this->at('10:00 AM', '12:00 PM'),
        ))->assertSessionHasErrors([
            'start_time' => 'Overlaps "Mon Wed Fri (9:00 AM – 11:00 AM)" on Wed. Slots cannot share the same time on the same day.',
        ]);

        $this->assertSame(1, AttendanceSlot::count());
    }

    public function test_a_slot_needs_at_least_one_day(): void
    {
        $this->create(['days' => []])->assertSessionHasErrors('days');

        $this->assertSame(0, AttendanceSlot::count());
    }

    public function test_days_are_stored_deduplicated_and_in_week_order(): void
    {
        $this->create(array_merge(['name' => 'Odd order', 'days' => [5, 1, 3, 1]], $this->at('9:00 AM', '11:00 AM')))
            ->assertSessionHasNoErrors();

        $slot = AttendanceSlot::firstWhere('name', 'Odd order');

        $this->assertSame([1, 3, 5], $slot->days);
        $this->assertSame('Mon, Wed, Fri', $slot->daysLabel());
    }

    public function test_lateness_falls_back_to_the_academy_start_on_a_day_the_slot_skips(): void
    {
        $slot = $this->existing('Weekdays', '09:00:00', '11:00:00', ['days' => [1, 2, 3, 4, 5]]);

        $student = User::factory()->create();
        $student->assignRole('student');
        $student->studentProfile()->create([
            'reg_no' => 'MD-TEST-1',
            'attendance_slot_id' => $slot->id,
        ]);

        // Monday 09:30 — half an hour into a slot with a 15 minute grace.
        $monday = \Illuminate\Support\Carbon::parse('2026-09-07 09:30:00');
        $this->assertSame(1, $monday->dayOfWeekIso);
        $this->assertSame('late', \App\Support\AttendanceConfig::statusForArrival($monday, $student->fresh()));

        // Saturday 09:30 — the slot does not run, so the academy-wide day
        // start applies instead, and that is 09:00 with the same grace.
        $saturday = \Illuminate\Support\Carbon::parse('2026-09-12 09:30:00');
        $this->assertSame(6, $saturday->dayOfWeekIso);
        $this->assertSame(
            \App\Support\AttendanceConfig::dayStart(),
            \App\Support\AttendanceConfig::sessionStart($saturday, $student->fresh())->format('H:i'),
        );
    }

    public function test_saving_a_slot_keeps_its_own_name(): void
    {
        $slot = $this->existing('Morning', '09:00:00', '11:00:00');

        $this->save($slot, array_merge(['name' => 'Morning', 'late_after_minutes' => 20], $this->at('9:00 AM', '11:00 AM')))
            ->assertSessionHasNoErrors();

        $this->assertSame(20, $slot->fresh()->late_after_minutes);
    }
}
