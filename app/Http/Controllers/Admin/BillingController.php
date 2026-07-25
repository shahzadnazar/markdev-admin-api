<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\FeePlan;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FeeSubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BillingController extends Controller
{
    /* ------------------------------ Fee plans ------------------------------ */

    public function plans(Request $request): View
    {
        $tab = in_array($request->query('tab'), ['defaulters', 'completed'], true) ? $request->query('tab') : 'all';

        $plans = FeePlan::query()
            ->with(['user.studentProfile:id,user_id,reg_no', 'course:id,title'])
            ->with(['invoices' => fn ($query) => $query->select('id', 'fee_plan_id', 'status', 'amount', 'fine_amount', 'due_at', 'sequence_no')])
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.trim($request->string('search')).'%';
                $query->where(fn ($inner) => $inner->where('title', 'like', $term)
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $term)));
            })
            ->when($tab === 'defaulters', fn ($query) => $query->whereHas('invoices', fn ($inner) => $inner->where('status', 'past_due')))
            ->when($tab === 'completed', fn ($query) => $query
                ->whereDoesntHave('invoices', fn ($inner) => $inner->whereIn('status', ['upcoming', 'open', 'pending', 'past_due']))
                ->whereHas('invoices'))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $outstanding = Invoice::whereIn('status', ['open', 'pending', 'past_due'])
            ->whereNotNull('fee_plan_id')
            ->selectRaw('coalesce(sum(amount + fine_amount), 0) as total')
            ->value('total');

        return view('admin.billing.plans.index', [
            'plans' => $plans,
            'tab' => $tab,
            'stats' => [
                'plans' => FeePlan::count(),
                'active' => FeePlan::where('is_active', true)->count(),
                'defaulters' => FeePlan::whereHas('invoices', fn ($query) => $query->where('status', 'past_due'))->count(),
                'outstanding' => (float) $outstanding,
            ],
        ]);
    }

    /** Full installment schedule of one plan. */
    public function showPlan(FeePlan $plan): View
    {
        $plan->load([
            'user.studentProfile:id,user_id,reg_no',
            'course:id,title',
            'invoices' => fn ($query) => $query->orderBy('sequence_no')->orderBy('due_at')->with('latestSubmission'),
        ]);

        $invoices = $plan->invoices;
        $paid = $invoices->where('status', 'paid');
        $openLike = $invoices->whereIn('status', ['open', 'pending', 'past_due']);

        return view('admin.billing.plans.show', [
            'plan' => $plan,
            'invoices' => $invoices,
            'summary' => [
                'paid_count' => $paid->count(),
                'total_count' => $invoices->count(),
                'collected' => $paid->sum(fn ($invoice) => (float) $invoice->amount + (float) $invoice->fine_amount),
                'outstanding' => $openLike->sum(fn ($invoice) => (float) $invoice->amount + (float) $invoice->fine_amount),
                'fines' => $invoices->sum(fn ($invoice) => (float) $invoice->fine_amount),
                'next_due' => $invoices->whereIn('status', ['upcoming', 'open', 'pending', 'past_due'])->sortBy('due_at')->first()?->due_at,
            ],
            'graceDays' => \App\Support\BillingConfig::graceDays(),
        ]);
    }

    public function createPlan(): View
    {
        return view('admin.billing.plans.form', [
            'plan' => null,
            'students' => User::role('student')->orderBy('name')->get(['id', 'name', 'email']),
            'courses' => Course::orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function storePlan(Request $request): RedirectResponse
    {
        $plan = FeePlan::create([...$this->validatedPlan($request), 'is_active' => true]);

        return redirect()->route('admin.billing.plans.index')->with('success', "Fee plan \"{$plan->title}\" created.");
    }

    public function editPlan(FeePlan $plan): View
    {
        return view('admin.billing.plans.form', [
            'plan' => $plan,
            'students' => User::role('student')->orderBy('name')->get(['id', 'name', 'email']),
            'courses' => Course::orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function updatePlan(Request $request, FeePlan $plan): RedirectResponse
    {
        $plan->update([...$this->validatedPlan($request), 'is_active' => $request->boolean('is_active')]);

        return redirect()->route('admin.billing.plans.index')->with('success', "Fee plan \"{$plan->title}\" updated.");
    }

    /* ------------------------------- Invoices ------------------------------ */

    public function invoices(Request $request): View
    {
        $invoices = Invoice::query()
            ->with(['user', 'feePlan'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.trim($request->string('search')).'%';
                $query->where(fn ($inner) => $inner
                    ->where('number', 'like', $term)
                    ->orWhere('title', 'like', $term)
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $term)));
            })
            ->latest('issued_at')
            ->paginate(12)
            ->withQueryString();

        return view('admin.billing.invoices.index', ['invoices' => $invoices]);
    }

    public function createInvoice(): View
    {
        return view('admin.billing.invoices.create', [
            'plans' => FeePlan::with('user')->orderBy('title')->get(),
            'nextNumber' => $this->nextInvoiceNumber(),
        ]);
    }

    public function storeInvoice(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'fee_plan_id' => ['required', Rule::exists('fee_plans', 'id')],
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'due_at' => ['required', 'date'],
        ]);

        $plan = FeePlan::findOrFail($data['fee_plan_id']);

        $invoice = Invoice::create([
            'fee_plan_id' => $plan->id,
            'user_id' => $plan->user_id,
            'number' => $this->nextInvoiceNumber(),
            'title' => $data['title'],
            'amount' => $data['amount'],
            'currency' => $plan->currency ?? 'USD',
            'status' => 'open',
            'issued_at' => now(),
            'due_at' => $data['due_at'],
        ]);

        return redirect()->route('admin.billing.invoices.show', $invoice)->with('success', "Invoice {$invoice->number} created.");
    }

    public function showInvoice(Invoice $invoice): View
    {
        $invoice->load(['user', 'feePlan.course', 'transactions.recorder']);

        return view('admin.billing.invoices.show', ['invoice' => $invoice]);
    }

    public function voidInvoice(Invoice $invoice): RedirectResponse
    {
        if ($invoice->status === 'paid') {
            return back()->with('error', 'A paid invoice cannot be voided.');
        }

        $invoice->update(['status' => 'void']);

        return back()->with('success', "Invoice {$invoice->number} voided.");
    }

    /** Record a manual payment: success transaction + mark the invoice paid. */
    public function recordPayment(Request $request, Invoice $invoice): RedirectResponse
    {
        if (in_array($invoice->status, ['paid', 'void'], true)) {
            return back()->with('error', 'This invoice is not payable.');
        }

        $data = $request->validate([
            'method_type' => ['required', Rule::in(['card', 'bank_transfer', 'wallet', 'cash', 'other'])],
            'method_brand' => ['nullable', 'string', 'max:40'],
            'method_last4' => ['nullable', 'string', 'size:4'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
        ]);

        Transaction::create([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'reference' => 'TRX-'.strtoupper(uniqid()),
            'description' => "Payment for {$invoice->number}",
            'method_type' => $data['method_type'],
            'method_brand' => $data['method_brand'] ?? null,
            'method_last4' => $data['method_last4'] ?? null,
            'amount' => $data['amount'],
            'currency' => $invoice->currency ?? 'USD',
            'status' => 'success',
            'recorded_by' => $request->user()->id,
        ]);

        $invoice->update(['status' => 'paid', 'paid_at' => now()]);

        return back()->with('success', 'Payment recorded — invoice marked paid.');
    }

    /* ----------------------------- Transactions ---------------------------- */

    public function transactions(Request $request): View
    {
        $transactions = Transaction::query()
            ->with(['user', 'invoice'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.trim($request->string('search')).'%';
                $query->where(fn ($inner) => $inner
                    ->where('reference', 'like', $term)
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $term)));
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.billing.transactions.index', ['transactions' => $transactions]);
    }

    /* -------------------------------- Support ------------------------------ */

    /** @return array<string, mixed> */
    protected function validatedPlan(Request $request): array
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')],
            'course_id' => ['nullable', Rule::exists('courses', 'id')],
            'title' => ['required', 'string', 'max:255'],
            'billing_cycle' => ['required', Rule::in(['one_time', 'monthly', 'annual'])],
            'currency' => ['required', 'string', 'size:3'],
            'total_amount' => ['required', 'numeric', 'min:0', 'max:9999999'],
        ]);

        $data['course_id'] = $data['course_id'] ?? null;
        $data['currency'] = strtoupper($data['currency']);

        return $data;
    }

    protected function nextInvoiceNumber(): string
    {
        $year = now()->year;
        $sequence = Invoice::withTrashed()->where('number', 'like', "INV-{$year}-%")->count() + 1;

        // Skip collisions from gaps left by older, manually numbered invoices.
        do {
            $number = sprintf('INV-%d-%04d', $year, $sequence);
            $sequence++;
        } while (Invoice::withTrashed()->where('number', $number)->exists());

        return $number;
    }

    /* ---------------------------- Fee submissions -------------------------- */

    public function submissions(Request $request): View
    {
        $status = in_array($request->query('status'), ['pending', 'success', 'rejected'], true)
            ? $request->query('status')
            : 'pending';

        $submissions = Transaction::query()
            ->with(['user', 'invoice', 'reviewer'])
            ->where('submitted_by_student', true)
            ->where('status', $status)
            ->when($request->filled('search'), function ($query) use ($request) {
                $like = '%'.trim($request->string('search')).'%';
                $query->where(fn ($inner) => $inner->where('reference', 'like', $like)
                    ->orWhere('reference_no', 'like', $like)
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $like)));
            })
            ->orderByDesc('created_at')
            ->paginate(10)
            ->withQueryString();

        return view('admin.billing.submissions', [
            'submissions' => $submissions,
            'status' => $status,
            'pendingCount' => Transaction::where('submitted_by_student', true)->where('status', 'pending')->count(),
        ]);
    }

    public function approveSubmission(Request $request, Transaction $transaction, FeeSubmissionService $service): RedirectResponse
    {
        $service->approve($transaction, $request->user());

        return back()->with('success', "Payment {$transaction->reference} approved — invoice marked paid.");
    }

    public function rejectSubmission(Request $request, Transaction $transaction, FeeSubmissionService $service): RedirectResponse
    {
        $data = $request->validate(
            ['rejection_reason' => ['required', 'string', 'min:5', 'max:500']],
            ['rejection_reason.required' => 'Tell the student why the submission is rejected.'],
        );

        $service->reject($transaction, $request->user(), $data['rejection_reason']);

        return back()->with('success', "Payment {$transaction->reference} rejected — the student has been notified.");
    }
}
