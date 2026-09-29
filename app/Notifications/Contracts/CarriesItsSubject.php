<?php

namespace App\Notifications\Contracts;

/**
 * A notification that names the THING it is about, so it is only ever sent once.
 *
 * The two scheduled notices — a milestone due tomorrow, a project gone overdue
 * — are produced by a daily command, and a command that runs twice on one
 * morning must not ring the bell twice. The subject is how that is decided:
 * "milestone:17:2026-10-06" is a fact about the data, and the second run finds
 * the row it already wrote rather than comparing timestamps and guessing.
 *
 * THE DATE IS PART OF THE SUBJECT on purpose. A milestone whose due date is
 * moved and comes round again is a NEW fact, and the person is owed a second
 * notice for it; a milestone that has not moved is the same fact however many
 * times the command runs. A subject of "milestone:17" alone could not tell
 * those apart, and the safe answer would have been to say nothing.
 *
 * Implementers MUST also put the subject in their `toDatabase` payload — it is
 * the stored row that PortalNotifier reads, not the object. TeamNotificationTest
 * asserts the stored row carries it, so the mechanism cannot be quietly dropped
 * by an edit to the payload.
 */
interface CarriesItsSubject
{
    public function subject(): string;
}
