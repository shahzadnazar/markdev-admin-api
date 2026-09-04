<?php

namespace Tests\Feature\Admin;

use App\Models\AbsenceFineCharge;
use App\Models\DailyAttendance;
use App\Models\Invoice;
use App\Models\LeaveApplication;
use App\Models\Setting;
use App\Models\User;
use App\Support\AbsenceFine;
use App\Support\AttendanceConfig;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AbsenceFineTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $instructor;

    protected User $manager;

    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->instructor = User::factory()->create();
        $this->instructor->assignRole('instructor');

        // Manager is the role that can open the daily register but may not
        // undo an absence — the case the rule actually decides.
        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');

        $this->student = User::factory()->create(['name' => 'Aliya Khan']);
        $this->student->assignRole('student');

        AttendanceConfig::setEditPin('4321');
        $this->configure(allowance: 2, fine: 150);
    }

    protected function configure(int $allowance, float $fine): void
    {
        Setting::updateOrCreate(['key' => 'monthly_absent_allowance'], ['value' => $allowance, 'group' => 'general']);
        Setting::updateOrCreate(['key' => 'absent_fine_amount'], ['value' => $fine, 'group' => 'general']);
        Setting::forgetCached();
    }

    /** @return array<int, DailyAttendance> */
    protected function absences(int $count, ?Carbon $month = null): array
    {
        $month ??= now();
        $rows = [];

        for ($day = 1; $day <= $count; $day++) {
            $rows[] = DailyAttendance::create([
                'user_id' => $this->student->id,
                'date' => $month->copy()->startOfMonth()->addDays($day)->toDateString(),
                'status' => 'absent',
                'source' => 'auto',
                'marked_at' => now(),
            ]);
        }

        return $rows;
    }

    protected function invoiceFor(string $status = 'open', float $amount = 5000): Invoice
    {
        return Invoice::create([
            'user_id' => $this->student->id,
            'number' => 'INV-'.uniqid(),
            'title' => 'Tuition',
            'amount' => $amount,
            'currency' => 'PKR',
            'status' => $status,
            'issued_at' => now(),
            'due_at' => now()->addDays(10),
            'paid_at' => $status === 'paid' ? now() : null,
        ]);
    }

    protected function balance(?Carbon $month = null): array
    {
        return AbsenceFine::balance($this->student->id, $month ?? now());
    }

    /* -------------------------------- Counting ------------------------------ */

    public function test_absences_inside_the_allowance_cost_nothing(): void
    {
        $this->absences(1);

        $this->assertSame(0, $this->balance()['chargeable']);
        $this->assertSame(0.0, $this->balance()['fine_total']);
    }

    public function test_the_allowance_itself_is_still_free(): void
    {
        $this->absences(2);

        $this->assertSame(2, $this->balance()['used']);
        $this->assertSame(0, $this->balance()['chargeable']);
        $this->assertSame(0.0, $this->balance()['fine_total']);
    }

    public function test_each_absence_beyond_the_allowance_is_charged_once(): void
    {
        $this->absences(5);

        $balance = $this->balance();
        $this->assertSame(5, $balance['used']);
        $this->assertSame(3, $balance['chargeable']);
        $this->assertSame(450.0, $balance['fine_total']);
    }

    public function test_a_zero_fine_charges_nothing_however_many_absences(): void
    {
        $this->configure(allowance: 2, fine: 0);
        $this->absences(9);

        $this->assertSame(7, $this->balance()['chargeable']);
        $this->assertSame(0.0, $this->balance()['fine_total']);

        $invoice = $this->invoiceFor();
        $this->artisan('attendance:charge-absent-fines', ['--month' => now()->toDateString()])->assertSuccessful();

        $this->assertSame(0.0, (float) $invoice->fresh()->absence_fine_amount);
    }

    public function test_a_month_stands_alone_with_no_carry_over(): void
    {
        $this->travelTo(Carbon::parse('2027-02-10 09:00'));
        $this->absences(5, Carbon::parse('2027-02-01'));

        $this->assertSame(3, $this->balance(Carbon::parse('2027-02-01'))['chargeable']);

        // March starts clean — February's overrun does not follow it.
        $this->travelTo(Carbon::parse('2027-03-10 09:00'));
        $this->assertSame(0, $this->balance()['used']);
        $this->assertSame(2, $this->balance()['remaining']);
        $this->assertSame(0, $this->balance()['chargeable']);
    }

    /* -------------------------------- Charging ------------------------------ */

    public function test_the_month_is_billed_onto_the_next_invoice(): void
    {
        $this->absences(5);
        $invoice = $this->invoiceFor();

        $this->artisan('attendance:charge-absent-fines', ['--month' => now()->toDateString()])->assertSuccessful();

        $invoice->refresh();
        $this->assertSame(450.0, (float) $invoice->absence_fine_amount);
        // Never folded into the tuition, and never into the late-payment fine.
        $this->assertSame(5000.0, (float) $invoice->amount);
        $this->assertSame(0.0, (float) $invoice->fine_amount);
        $this->assertSame(5450.0, $invoice->payable_total);
    }

    public function test_running_the_charge_twice_does_not_double_bill(): void
    {
        $this->absences(5);
        $invoice = $this->invoiceFor();

        $this->artisan('attendance:charge-absent-fines', ['--month' => now()->toDateString()])->assertSuccessful();
        $this->artisan('attendance:charge-absent-fines', ['--month' => now()->toDateString()])->assertSuccessful();

        $this->assertSame(450.0, (float) $invoice->fresh()->absence_fine_amount);
        $this->assertSame(1, AbsenceFineCharge::where('user_id', $this->student->id)->count());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->absences(5);
        $invoice = $this->invoiceFor();

        $this->artisan('attendance:charge-absent-fines', ['--month' => now()->toDateString(), '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(0.0, (float) $invoice->fresh()->absence_fine_amount);
        $this->assertSame(0, AbsenceFineCharge::count());
    }

    /* ------------------------------- Corrections ---------------------------- */

    protected function correct(User $actor, DailyAttendance $record, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($actor)->put("/admin/attendance/daily/{$record->id}", array_merge([
            'pin' => '4321',
            'status' => 'present',
            'reason' => 'Marked in error — the register was filled before the punch synced.',
        ], $payload));
    }

    public function test_an_instructor_cannot_reach_the_register_at_all(): void
    {
        [$record] = $this->absences(1);

        // Stronger than the absence rule: `attendance.daily` closes the whole
        // register to instructors, so they never get as far as a correction.
        $this->correct($this->instructor, $record)->assertForbidden();

        $this->assertSame('absent', $record->fresh()->status);
    }

    public function test_a_manager_cannot_undo_an_absence(): void
    {
        [$record] = $this->absences(1);

        $this->correct($this->manager, $record)->assertForbidden();

        $this->assertSame('absent', $record->fresh()->status);
    }

    public function test_a_manager_may_still_correct_a_day_that_is_not_absent(): void
    {
        // Proves the 403 above is the absence rule, not the route being closed
        // to managers generally.
        $record = DailyAttendance::create([
            'user_id' => $this->student->id,
            'date' => now()->startOfMonth()->addDay()->toDateString(),
            'status' => 'late',
            'source' => 'manual',
            'marked_at' => now(),
        ]);

        $this->correct($this->manager, $record)->assertSessionHas('success');

        $this->assertSame('present', $record->fresh()->status);
    }

    public function test_an_admin_corrects_it_with_a_reason_and_it_is_logged(): void
    {
        [$record] = $this->absences(1);

        $this->correct($this->admin, $record)->assertSessionHas('success');

        $this->assertSame('present', $record->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'attendance_corrected',
            'module' => 'daily_attendance',
            'record_id' => $this->student->id,
            'user_id' => $this->admin->id,
        ]);

        $log = \App\Models\AuditLog::where('action', 'attendance_corrected')->latest('id')->first();
        $this->assertSame('absent', $log->old_values['status']);
        $this->assertSame('present', $log->new_values['status']);
        $this->assertStringContainsString('Marked in error', $log->new_values['reason']);
        $this->assertSame($record->date->toDateString(), $log->new_values['date']);
        $this->assertSame('Aliya Khan', $log->new_values['student']);
    }

    public function test_a_correction_without_a_reason_is_refused(): void
    {
        [$record] = $this->absences(1);

        $this->correct($this->admin, $record, ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame('absent', $record->fresh()->status);
    }

    public function test_a_super_admin_may_correct_it_too(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');
        [$record] = $this->absences(1);

        $this->correct($superAdmin, $record)->assertSessionHas('success');

        $this->assertSame('present', $record->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'attendance_corrected',
            'user_id' => $superAdmin->id,
        ]);
    }

    public function test_a_correction_before_billing_just_lowers_the_total(): void
    {
        $records = $this->absences(5);
        $this->assertSame(450.0, $this->balance()['fine_total']);

        $this->correct($this->admin, $records[4])->assertSessionHas('success');

        $this->assertSame(300.0, $this->balance()['fine_total']);
        // Nothing was billed, so nothing to credit.
        $this->assertSame(0, AbsenceFineCharge::count());
        $this->assertSame(0.0, (float) Invoice::sum('absence_fine_credit'));
    }

    public function test_a_correction_after_billing_credits_the_next_invoice(): void
    {
        $records = $this->absences(5);
        $billed = $this->invoiceFor();
        $this->artisan('attendance:charge-absent-fines', ['--month' => now()->toDateString()]);

        $next = $this->invoiceFor(amount: 5000);
        $next->update(['due_at' => now()->addMonth()]);

        $this->correct($this->admin, $records[4])->assertSessionHas('success');

        // The invoice that was issued is untouched.
        $billed->refresh();
        $this->assertSame(450.0, (float) $billed->absence_fine_amount);
        $this->assertSame(0.0, (float) $billed->absence_fine_credit);

        // The credit lands on the next one as its own line.
        $this->assertSame(150.0, (float) $next->fresh()->absence_fine_credit);
        $this->assertSame(4850.0, $next->fresh()->payable_total);
    }

    public function test_a_paid_invoice_is_never_touched(): void
    {
        $records = $this->absences(5);
        $paid = $this->invoiceFor();
        $this->artisan('attendance:charge-absent-fines', ['--month' => now()->toDateString()]);
        $paid->update(['status' => 'paid', 'paid_at' => now()]);

        $next = $this->invoiceFor();

        $this->correct($this->admin, $records[4])->assertSessionHas('success');

        $paid->refresh();
        $this->assertSame('paid', $paid->status);
        $this->assertSame(450.0, (float) $paid->absence_fine_amount);
        $this->assertSame(0.0, (float) $paid->absence_fine_credit);
        $this->assertSame(150.0, (float) $next->fresh()->absence_fine_credit);
    }

    public function test_approving_leave_for_a_closed_absence_releases_it(): void
    {
        $records = $this->absences(5);
        $invoice = $this->invoiceFor();
        $this->artisan('attendance:charge-absent-fines', ['--month' => now()->toDateString()]);
        $next = $this->invoiceFor();

        $day = $records[4]->date->toDateString();
        $leave = LeaveApplication::create([
            'user_id' => $this->student->id,
            'from_date' => $day,
            'to_date' => $day,
            'reason' => 'Hospital appointment',
        ]);
        $leave->openDecisions();

        $this->actingAs($this->admin)->post("/admin/leaves/{$leave->id}/review", ['days' => [$day]])
            ->assertSessionHas('success');

        // The day stops being an absence, and the fine follows it.
        $this->assertSame('leave', $records[4]->fresh()->status);
        $this->assertSame(2, $this->balance()['chargeable']);
        $this->assertSame(150.0, (float) $next->fresh()->absence_fine_credit);
        $this->assertSame(450.0, (float) $invoice->fresh()->absence_fine_amount);
    }

    public function test_a_present_day_is_not_rewritten_by_a_late_approval(): void
    {
        $day = now()->startOfMonth()->addDay()->toDateString();
        $record = DailyAttendance::create([
            'user_id' => $this->student->id,
            'date' => $day,
            'status' => 'present',
            'source' => 'manual',
            'marked_at' => now(),
        ]);

        $leave = LeaveApplication::create([
            'user_id' => $this->student->id,
            'from_date' => $day,
            'to_date' => $day,
            'reason' => 'Applied but came in anyway',
        ]);
        $leave->openDecisions();

        $this->actingAs($this->admin)->post("/admin/leaves/{$leave->id}/review", ['days' => [$day]]);

        $this->assertSame('present', $record->fresh()->status);
    }

    /* -------------------------------- Settings ------------------------------ */

    protected function saveSettings(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        return $this->actingAs($superAdmin)->put('/admin/settings', array_merge([
            'site_name' => 'MarkDev',
            'registration_fee' => 2000,
            'defaulter_fine_per_day' => 100,
            'billing_grace_days' => 5,
            'billing_activation_days' => 5,
            'attendance_day_start_hour' => 9,
            'attendance_day_start_minute' => 0,
            'attendance_day_start_meridiem' => 'AM',
            'attendance_late_after_minutes' => 15,
            'monthly_leave_allowance' => 2,
            'monthly_absent_allowance' => 3,
            'absent_fine_amount' => 250,
            'attendance_mode' => AttendanceConfig::MODE_MANUAL,
        ], $overrides));
    }

    public function test_a_zero_absent_allowance_is_refused(): void
    {
        $this->saveSettings(['monthly_absent_allowance' => 0])
            ->assertSessionHasErrors(['monthly_absent_allowance' => 'Monthly absent allowance must be at least 1.']);

        Setting::forgetCached();
        $this->assertSame(2, AbsenceFine::allowance());
    }

    public function test_a_zero_fine_is_allowed(): void
    {
        $this->saveSettings(['absent_fine_amount' => 0])->assertSessionHasNoErrors();

        Setting::forgetCached();
        $this->assertSame(0.0, AbsenceFine::perAbsence());
    }

    public function test_the_late_payment_fine_is_a_different_setting(): void
    {
        $this->saveSettings(['defaulter_fine_per_day' => 75, 'absent_fine_amount' => 250])
            ->assertSessionHasNoErrors();

        Setting::forgetCached();
        $this->assertSame(75.0, \App\Support\BillingConfig::finePerDay());
        $this->assertSame(250.0, AbsenceFine::perAbsence());
    }

    public function test_changing_the_settings_moves_the_api_at_once(): void
    {
        $this->absences(5);

        \Laravel\Sanctum\Sanctum::actingAs($this->student);
        $this->getJson('/api/v1/attendance/summary')->assertOk()
            ->assertJsonPath('data.absence_balance.allowance', 2)
            ->assertJsonPath('data.absence_balance.used', 5)
            ->assertJsonPath('data.absence_balance.chargeable', 3)
            ->assertJsonPath('data.absence_balance.fine_per_absent', 150)
            ->assertJsonPath('data.absence_balance.fine_total', 450);

        $this->configure(allowance: 4, fine: 100);

        \Laravel\Sanctum\Sanctum::actingAs($this->student);
        $this->getJson('/api/v1/attendance/summary')->assertOk()
            ->assertJsonPath('data.absence_balance.allowance', 4)
            ->assertJsonPath('data.absence_balance.chargeable', 1)
            ->assertJsonPath('data.absence_balance.fine_total', 100);
    }
}
