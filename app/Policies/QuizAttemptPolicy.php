<?php

namespace App\Policies;

use App\Models\QuizAttempt;
use App\Models\User;

class QuizAttemptPolicy
{
    public function view(User $user, QuizAttempt $attempt): bool
    {
        return $attempt->user_id === $user->id;
    }

    public function submit(User $user, QuizAttempt $attempt): bool
    {
        return $attempt->user_id === $user->id;
    }

    /**
     * Writing tab-activity totals onto an attempt.
     *
     * Its own student only. Ownership is the whole check here: whether the
     * attempt is still open is a state question the controller answers, so
     * that a submitted attempt gives a clearer refusal than "forbidden".
     */
    public function record(User $user, QuizAttempt $attempt): bool
    {
        return $attempt->user_id === $user->id;
    }
}
