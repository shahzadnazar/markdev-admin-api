<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\InvoiceResource;
use App\Http\Resources\TransactionResource;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class BillingController extends ApiController
{
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();

        $plan = $user->feePlans()->where('is_active', true)->latest()->first();

        if ($plan === null) {
            return response()->json([
                'data' => [
                    'plan_title' => null,
                    'billing_cycle' => null,
                    'billing_active' => false,
                    'currency' => 'USD',
                    'total_amount' => 0,
                    'paid_amount' => 0,
                    'remaining_amount' => 0,
                    'paid_percent' => 0,
                    'next_due_at' => null,
                    'next_invoice' => null,
                    'statement_url' => null,
                ],
            ]);
        }

        $total = (float) $plan->total_amount;
        $paid = (float) $plan->invoices()->where('status', 'paid')->sum('amount');
        $remaining = round(max($total - $paid, 0), 2);

        $nextInvoice = $plan->invoices()
            ->whereIn('status', ['open', 'past_due'])
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
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
                'next_due_at' => $nextInvoice?->due_at?->toISOString(),
                'next_invoice' => $nextInvoice ? (new InvoiceResource($nextInvoice))->resolve() : null,
                'statement_url' => null,
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
        $query = Invoice::where('user_id', $request->user()->id);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn (Builder $q) => $q
                ->where('number', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%"));
        }

        $invoices = $query->orderByDesc('issued_at')->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return InvoiceResource::collection($invoices);
    }

    public function payInvoice(Request $request, Invoice $invoice, PaymentService $payments): JsonResponse
    {
        Gate::authorize('pay', $invoice);

        if (! in_array($invoice->status, ['open', 'past_due'], true)) {
            throw ValidationException::withMessages([
                'invoice' => ['Only open or past-due invoices can be paid.'],
            ]);
        }

        $transaction = $payments->settleInvoice($request->user(), $invoice);

        return response()->json([
            'data' => [
                'checkout_url' => null,
                'transaction' => (new TransactionResource($transaction))->resolve(),
            ],
        ]);
    }

    public function retryTransaction(Request $request, Transaction $transaction, PaymentService $payments): JsonResponse
    {
        Gate::authorize('retry', $transaction);

        if ($transaction->status !== 'failed') {
            throw ValidationException::withMessages([
                'transaction' => ['Only failed transactions can be retried.'],
            ]);
        }

        $invoice = $transaction->invoice;

        if ($invoice === null || ! in_array($invoice->status, ['open', 'past_due'], true)) {
            throw ValidationException::withMessages([
                'transaction' => ['The related invoice is no longer payable.'],
            ]);
        }

        $settled = $payments->settleInvoice($request->user(), $invoice, [
            'type' => $transaction->method_type,
            'brand' => $transaction->method_brand,
            'last4' => $transaction->method_last4,
        ]);

        return response()->json([
            'data' => [
                'checkout_url' => null,
                'transaction' => (new TransactionResource($settled))->resolve(),
            ],
        ]);
    }
}
