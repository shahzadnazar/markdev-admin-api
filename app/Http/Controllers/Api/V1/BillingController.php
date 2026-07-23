<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\SubmitFeeRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\TransactionResource;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\FeeSubmissionService;
use App\Support\BillingConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class BillingController extends ApiController
{
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();

        $plan = $user->feePlans()->where('is_active', true)->latest()->first();

        $channels = collect(FeeSubmissionService::CHANNELS)
            ->map(fn (array $channel, string $value) => ['value' => $value, 'label' => $channel['label']])
            ->values();

        $supportPhone = Setting::where('key', 'support_phone')->value('value');

        if ($plan === null) {
            return response()->json([
                'data' => [
                    'plan_title' => null,
                    'billing_cycle' => null,
                    'billing_active' => false,
                    'currency' => 'PKR',
                    'total_amount' => 0,
                    'paid_amount' => 0,
                    'remaining_amount' => 0,
                    'paid_percent' => 0,
                    'pending_review_amount' => 0,
                    'next_due_at' => null,
                    'next_invoice' => null,
                    'pending_invoice' => null,
                    'statement_url' => null,
                    'payment_channels' => $channels,
                    'support_phone' => $supportPhone,
                    'installments' => null,
                ],
            ]);
        }

        $total = (float) $plan->total_amount;
        $paid = (float) $plan->invoices()->where('status', 'paid')->sum('amount');
        $pendingReview = (float) $plan->invoices()->where('status', 'pending')->sum('amount');
        $remaining = round(max($total - $paid, 0), 2);

        $nextInvoice = $plan->invoices()
            ->with('latestSubmission')
            ->whereIn('status', ['open', 'past_due'])
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->orderBy('id')
            ->first();

        $pendingInvoice = $plan->invoices()
            ->with('latestSubmission')
            ->where('status', 'pending')
            ->orderBy('id')
            ->first();

        return response()->json([
            'data' => [
                'plan_title' => $plan->title,
                'billing_cycle' => $plan->billing_cycle,
                'billing_active' => (bool) $plan->is_active,
                'currency' => $plan->currency,
                'total_amount' => $total,
                'paid_amount' => $paid,
                'remaining_amount' => $remaining,
                'paid_percent' => $total > 0 ? round(min($paid / $total * 100, 100), 1) : 0,
                'pending_review_amount' => $pendingReview,
                'next_due_at' => $nextInvoice?->due_at?->toISOString(),
                'next_invoice' => $nextInvoice ? (new InvoiceResource($nextInvoice))->resolve() : null,
                'pending_invoice' => $pendingInvoice ? (new InvoiceResource($pendingInvoice))->resolve() : null,
                'statement_url' => null,
                'payment_channels' => $channels,
                'support_phone' => $supportPhone,
                'installments' => $plan->installment_months ? [
                    'months' => $plan->installment_months,
                    'due_day' => $plan->due_day,
                    'fine_per_day' => BillingConfig::finePerDay($plan),
                    'grace_days' => BillingConfig::graceDays(),
                    'activation_days' => BillingConfig::activationDays(),
                    'paid_count' => $plan->invoices()->where('status', 'paid')->count(),
                    'defaulted_fine_total' => (float) $plan->invoices()->where('status', 'past_due')->sum('fine_amount'),
                ] : null,
            ],
        ]);
    }

    public function transactions(Request $request): AnonymousResourceCollection
    {
        $query = Transaction::where('user_id', $request->user()->id);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn (Builder $q) => $q
                ->where('reference', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%"));
        }

        $transactions = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return TransactionResource::collection($transactions);
    }

    public function invoices(Request $request): AnonymousResourceCollection
    {
        $query = Invoice::with('latestSubmission')->where('user_id', $request->user()->id);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn (Builder $q) => $q
                ->where('number', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%"));
        }

        $invoices = $query->orderByRaw('sequence_no is null')
            ->orderBy('sequence_no')
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return InvoiceResource::collection($invoices);
    }

    /** Student uploads proof of payment; the submission awaits admin review. */
    public function submitPayment(
        SubmitFeeRequest $request,
        Invoice $invoice,
        FeeSubmissionService $submissions,
    ): JsonResponse {
        Gate::authorize('pay', $invoice);

        $transaction = $submissions->submit(
            $request->user(),
            $invoice,
            $request->safe()->except('receipt'),
            $request->file('receipt'),
        );

        return response()->json([
            'data' => (new TransactionResource($transaction))->resolve(),
        ], 201);
    }
}
