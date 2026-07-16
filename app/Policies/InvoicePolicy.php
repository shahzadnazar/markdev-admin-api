<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        return $invoice->user_id === $user->id;
    }

    public function pay(User $user, Invoice $invoice): bool
    {
        return $invoice->user_id === $user->id;
    }
}
