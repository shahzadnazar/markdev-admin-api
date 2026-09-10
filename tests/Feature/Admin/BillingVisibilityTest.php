<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Money is shown to whoever holds billing.view, and to nobody else.
 *
 * Gated on the permission, never on a role name. `hasRole('instructor')` would
 * have worked today and failed silently the day a fifth role was added — the
 * new role would inherit "not an instructor" and see the money. The permission
 * is the thing that actually means "may see money", so it is the thing that is
 * checked.
 *
 * The assertion that matters most is that the FIGURES ARE ABSENT from the
 * response, not merely unrendered. A Blade @can with the query still running
 * leaves the amounts in the HTML for anyone who opens devtools, and that is
 * not hiding, it is obscuring.
 */
class BillingVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /** The amounts that must never reach a viewer without billing.view. */
    private const AMOUNT = 60000;

    protected User $instructor;

    protected User $admin;

    protected User $manager;

    protected Course $course;

    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $category = Category::create(['name' => 'Web', 'slug' => 'web-'.Str::random(4)]);

        $this->instructor = User::factory()->create(['name' => 'Web Instructor']);
        $this->instructor->assignRole('instructor');

        $this->admin = User::factory()->create(['name' => 'The Admin']);
        $this->admin->assignRole('admin');

        $this->manager = User::factory()->create(['name' => 'The Manager']);
        $this->manager->assignRole('manager');

        $this->course = Course::create([
            'title' => 'Laravel Basics',
            'slug' => 'laravel-'.Str::random(6),
            'excerpt' => 'x',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'is_free' => false,
            'price' => 60000,
            'category_id' => $category->id,
            // Category scoping (77b6fd1): this instructor owns the course, so
            // the enrollment is genuinely on their list. Hiding the money must
            // not be achieved by hiding the row.
            'instructor_id' => $this->instructor->id,
        ]);

        $this->student = User::factory()->create(['name' => 'A Student']);
        $this->student->assignRole('student');

        Enrollment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'enrolled_at' => now()->subWeek(),
            'progress_percent' => 40,
        ]);

        $plan = FeePlan::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'title' => 'Laravel Basics fee',
            'billing_cycle' => 'monthly',
            'currency' => 'PKR',
            'total_amount' => self::AMOUNT,
            'is_active' => true,
        ]);

        foreach (range(1, 7) as $n) {
            Invoice::create([
                'fee_plan_id' => $plan->id,
                'user_id' => $this->student->id,
                'number' => 'INV-'.Str::random(6),
                'title' => "Installment {$n}",
                'amount' => self::AMOUNT / 7,
                'currency' => 'PKR',
                'status' => 'open',
                'issued_at' => now()->subDays(10),
                'due_at' => now()->addMonth(),
            ]);
        }
    }

    protected function enrollments(User $as): string
    {
        return $this->actingAs($as)
            ->get(route('admin.enrollments.index'))
            ->assertOk()
            ->getContent();
    }

    // ------------------------------------------------- the enrollments list

    public function test_an_instructor_sees_no_fee_column_at_all(): void
    {
        $page = $this->enrollments($this->instructor);

        // Header AND cells. A header with empty cells under it is worse than
        // no column — it advertises that something is being withheld.
        $this->assertStringNotContainsString('>Fee<', $page);
        $this->assertStringNotContainsString('paid', $page);
        $this->assertStringNotContainsString('Add fee', $page);
        $this->assertStringNotContainsString('no fee plan', $page);
    }

    public function test_the_instructors_page_contains_no_fee_amounts_anywhere(): void
    {
        // The test that proves this is not CSS. Every shape the amount could
        // take in the response, including the raw number the model holds.
        $page = $this->enrollments($this->instructor);

        foreach (['60,000', '60000', '60000.00', 'Rs ', '0/7', '/7 paid'] as $needle) {
            $this->assertStringNotContainsString($needle, $page, "the response still carries [{$needle}]");
        }
    }

    public function test_the_instructors_view_is_never_handed_the_fee_data(): void
    {
        /*
         * The Blade gate and the query gate are two different properties, and
         * the rendered HTML cannot tell them apart: with the query still
         * running and only @can hiding the cell, the page looks identical and
         * every assertion above still passes. So this reaches past the
         * markup and checks what the controller actually put in the view.
         *
         * It matters because the two fail differently. Someone later moving
         * the Fee cell into a component, or adding a debug dump, or shipping
         * the collection to a JS island, would expose numbers the controller
         * should never have fetched.
         */
        $this->actingAs($this->instructor)
            ->get(route('admin.enrollments.index'))
            ->assertOk()
            ->assertViewHas('plans', fn ($plans) => $plans->isEmpty());
    }

    public function test_an_admin_is_handed_the_fee_data(): void
    {
        // The other half: the gate must not have made the column permanently
        // empty for the people it is for.
        $this->actingAs($this->admin)
            ->get(route('admin.enrollments.index'))
            ->assertOk()
            ->assertViewHas('plans', fn ($plans) => $plans->count() === 1
                && (int) $plans->first()->total_amount === self::AMOUNT
                && (int) $plans->first()->total_invoices === 7);
    }

    public function test_an_instructor_still_sees_the_enrollment_itself(): void
    {
        // Removing the money must not remove the row. The list has to keep
        // working for them, minus the fee.
        $page = $this->enrollments($this->instructor);

        $this->assertStringContainsString('A Student', $page);
        $this->assertStringContainsString('Laravel Basics', $page);
        $this->assertStringContainsString('Progress', $page);
        $this->assertStringContainsString('Status', $page);
    }

    public function test_an_admin_sees_the_fee_column_exactly_as_before(): void
    {
        $page = $this->enrollments($this->admin);

        $this->assertStringContainsString('>Fee<', $page);
        $this->assertStringContainsString('0/7 paid', $page);
        $this->assertStringContainsString('Rs 60,000', $page);
    }

    public function test_an_admin_sees_add_fee_where_there_is_no_plan(): void
    {
        $other = User::factory()->create(['name' => 'Unbilled Student']);
        $other->assignRole('student');
        Enrollment::create([
            'user_id' => $other->id,
            'course_id' => $this->course->id,
            'enrolled_at' => now(),
            'progress_percent' => 0,
        ]);

        $this->assertStringContainsString('Add fee', $this->enrollments($this->admin));
    }

    public function test_the_add_fee_route_refuses_an_instructor_directly(): void
    {
        // Guessing the URL must not produce a form. This was already gated on
        // enrollments.create, which an instructor does not hold — asserted so
        // it stays that way rather than assumed.
        $this->actingAs($this->instructor)
            ->get(route('admin.enrollments.create', ['enroll' => $this->student->id, 'pick' => $this->course->id]))
            ->assertForbidden();
    }

    public function test_the_live_search_partial_hides_it_too(): void
    {
        // The list re-renders through ?partial=1, which returns the same
        // Blade without the layout. A gate on the page and not the partial
        // would leak on the first keystroke.
        $page = $this->actingAs($this->instructor)
            ->get(route('admin.enrollments.index', ['partial' => 1]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Rs ', $page);
        $this->assertStringNotContainsString('>Fee<', $page);
    }

    // ------------------------------------------------------- the dashboard

    public function test_a_manager_no_longer_sees_the_fee_review_widget(): void
    {
        // The dashboard leak was never an instructor's: they get a different
        // dashboard entirely. It was the manager, who holds no billing
        // permission and was being shown a count of payment submissions and a
        // link to a screen that would have answered 403.
        $page = $this->actingAs($this->manager)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Fee review', $page);
        $this->assertStringNotContainsString('Fee submissions awaiting review', $page);
        $this->assertStringNotContainsString('Installments past due', $page);
    }

    public function test_the_managers_dashboard_is_never_handed_the_billing_counts(): void
    {
        // Same reasoning as the enrollments view: @can in the Blade hides the
        // widget whether or not the controller ran the query, so the response
        // alone proves nothing about whether the count was computed.
        $this->actingAs($this->manager)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('stats', fn ($stats) => $stats['pending_fees'] === null)
            ->assertViewHas('attention', fn ($attention) => collect($attention)
                ->pluck('label')
                ->every(fn ($label) => ! str_contains($label, 'Fee') && ! str_contains($label, 'Installments')));
    }

    public function test_an_admin_still_sees_the_fee_review_widget(): void
    {
        $page = $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Fee review', $page);
    }

    public function test_the_instructor_dashboard_carries_no_money(): void
    {
        // Already true before this change — pinned so it stays true.
        $page = $this->actingAs($this->instructor)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        foreach (['Fee review', 'Rs ', 'Installments past due', 'invoice'] as $needle) {
            $this->assertStringNotContainsString($needle, $page);
        }
    }

    // --------------------------------------- what was already correct, pinned

    public function test_every_billing_screen_already_refuses_an_instructor(): void
    {
        // These were correct before this change — all of them sit behind
        // can:billing.view on the route. Pinned so the sweep that established
        // it does not have to be repeated by hand next time.
        foreach ([
            'admin.billing.plans.index',
            'admin.billing.invoices.index',
            'admin.billing.submissions',
            'admin.billing.transactions.index',
            'admin.billing.payment-methods.index',
        ] as $route) {
            $this->actingAs($this->instructor)
                ->get(route($route))
                ->assertForbidden();
        }
    }

    public function test_the_course_catalogue_price_stays_visible_to_an_instructor(): void
    {
        /*
         * Deliberately NOT hidden, and worth writing down because it looks
         * like the same leak and is not.
         *
         * A course's price is the product's list price — catalogue metadata
         * the instructor sets themselves on their own course form, where the
         * field is labelled "Fee (Rs)" and they hold courses.update. It is not
         * a student's balance, nobody owes it, and hiding it from the list
         * while leaving the input on the form they own would be incoherent.
         *
         * What "money is not their business" covers is what a STUDENT owes:
         * fee plans, invoices, paid counts, outstanding amounts. That is the
         * line this test marks, so a later sweep does not cross it by reflex.
         */
        $page = $this->actingAs($this->instructor)
            ->get(route('admin.courses.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Rs 60,000', $page, 'the catalogue price is the instructor\'s own field');

        // And it is still their own field to edit.
        $this->actingAs($this->instructor)
            ->get(route('admin.courses.edit', $this->course))
            ->assertOk()
            ->assertSee('Fee (Rs)');
    }

    // ------------------------------------------- the gate is the permission

    public function test_granting_billing_view_is_what_restores_the_column(): void
    {
        // The proof that this is gated on the permission and not on the role
        // name: the same instructor, unchanged in every other way, sees the
        // column the moment they hold billing.view.
        $this->assertStringNotContainsString('Rs 60,000', $this->enrollments($this->instructor));

        $this->instructor->givePermissionTo('billing.view');
        $this->instructor->refresh();

        $page = $this->enrollments($this->instructor);
        $this->assertStringContainsString('>Fee<', $page);
        $this->assertStringContainsString('Rs 60,000', $page);
    }
}
