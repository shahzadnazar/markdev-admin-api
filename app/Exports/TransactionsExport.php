<?php

namespace App\Exports;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class TransactionsExport implements FromQuery, WithHeadings, WithMapping
{
    public function query(): Builder
    {
        return Transaction::query()
            ->with(['user', 'invoice'])
            ->latest();
    }

    public function headings(): array
    {
        return ['Reference', 'Invoice', 'Student', 'Email', 'Method', 'Brand', 'Last 4', 'Amount', 'Currency', 'Status', 'Date'];
    }

    /** @param Transaction $transaction */
    public function map($transaction): array
    {
        return [
            $transaction->reference,
            $transaction->invoice?->number,
            $transaction->user?->name,
            $transaction->user?->email,
            $transaction->method_type,
            $transaction->method_brand,
            $transaction->method_last4,
            (float) $transaction->amount,
            $transaction->currency ?? 'USD',
            $transaction->status,
            $transaction->created_at?->toDateTimeString(),
        ];
    }
}
