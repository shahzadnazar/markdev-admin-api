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
use Barryvdh\DomPDF\Facade\Pdf;
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
        // The invoice header used to print "MARKDEV" no matter what the academy
        // had renamed itself to in Settings.
        $siteName = Setting::cached('site_name') ?: config('app.name', 'MarkDev');

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
                    'payment_methods' => \App\Models\PaymentMethod::availableForCourse(null)
                        ->map(fn ($method) => $this->methodPayload($method))->values(),
                    'support_phone' => $supportPhone,
                'site_name' => $siteName,
                    'installments' => null,
                    'admission' => null,
                ],
            ]);
        }

        $total = (float) $plan->total_amount;
        $paid = (float) $plan->invoices()->where('type', 'installment')->where('status', 'paid')->sum('amount');
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

        // Unsettled admission charges: the registration fee and the advance
        // first installment (the one due on the admission day itself).
        $admissionInvoices = $plan->invoices()
            ->with('latestSubmission')
            ->whereIn('status', ['open', 'pending', 'past_due'])
            ->where(function ($query) use ($plan) {
                $query->where('type', 'registration')
                    ->orWhere(fn ($inner) => $inner->where('sequence_no', 1)->whereDate('due_at', $plan->starts_at));
            })
            ->orderByRaw("type = 'registration' desc")
            ->get();

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
                'payment_methods' => \App\Models\PaymentMethod::availableForCourse($plan->course_id)
                    ->map(fn ($method) => $this->methodPayload($method))->values(),
                'support_phone' => $supportPhone,
                'site_name' => $siteName,
                'admission' => $admissionInvoices->isEmpty() ? null : [
                    'invoices' => $admissionInvoices->map(fn ($invoice) => (new InvoiceResource($invoice))->resolve())->values(),
                    'total_due' => round($admissionInvoices->whereIn('status', ['open', 'past_due'])
                        ->sum(fn ($invoice) => $invoice->payable_total), 2),
                ],
                'installments' => $plan->installment_months ? [
                    'months' => $plan->installment_months,
                    'due_day' => $plan->due_day,
                    'fine_per_day' => BillingConfig::finePerDay($plan),
                    'grace_days' => BillingConfig::graceDays(),
                    'activation_days' => BillingConfig::activationDays(),
                    'paid_count' => $plan->invoices()->where('type', 'installment')->where('status', 'paid')->count(),
                    'defaulted_fine_total' => (float) $plan->invoices()->where('status', 'past_due')->sum('fine_amount'),
                ] : null,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    protected function methodPayload(\App\Models\PaymentMethod $method): array
    {
        return [
            'id' => $method->id,
            'name' => $method->name,
            'channel' => $method->channel,
            'channel_label' => $method->channelLabel(),
            'account_title' => $method->account_title,
            'account_number' => $method->account_number,
            'bank_name' => $method->bank_name,
            'instructions' => $method->instructions,
        ];
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

    /**
     * Streams a paid-invoice receipt PDF. Reached through a signed URL
     * (validated by the `signed` middleware) so the portal can link to it
     * with a plain <a href> — no Authorization header needed.
     */
    public function receipt(Invoice $invoice): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($invoice->status === 'paid', 404);

        $invoice->load(['user', 'feePlan.course', 'latestSubmission']);

        $pdf = Pdf::loadView('pdf.invoice-receipt', ['invoice' => $invoice])->setPaper('a4');

        return $pdf->download("receipt-{$invoice->number}.pdf");
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
