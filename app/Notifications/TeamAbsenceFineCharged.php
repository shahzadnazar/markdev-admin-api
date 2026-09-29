<?php

namespace App\Notifications;

use App\Models\TeamAbsenceFine;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A month's absences went past the allowance and a ledger row was written.
 *
 * Sent from TeamFineRules::charge, which writes one row per person per month
 * and leaves an existing one alone — so the ROW is the idempotency and this
 * needs no subject of its own. A second run of the month-end command finds the
 * row, creates nothing, and rings nothing.
 *
 * Only when something is actually owed. A month that came to nothing still gets
 * its row, because "settled at zero" and "never looked at" have to be different
 * — but it is not a charge and there is nothing to tell anybody about.
 *
 * The figures are the recipient's OWN. That is the only money anywhere in the
 * team portal's notifications; a client's contract value appears in none of
 * them.
 */
class TeamAbsenceFineCharged extends Notification
{
    use Queueable;

    public function __construct(public TeamAbsenceFine $fine) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'An absence fine was charged',
            'message' => sprintf(
                '%s: %d absence(s), %d past your allowance of %d. %s owed.',
                $this->fine->month->format('F Y'),
                $this->fine->absences,
                $this->fine->chargeable,
                $this->fine->allowance,
                number_format((float) $this->fine->total, 2),
            ),
            'action_url' => route('admin.team-fines.mine', absolute: false),
        ];
    }
}
