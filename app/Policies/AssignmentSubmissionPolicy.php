<?php

namespace App\Policies;

use App\Models\AssignmentSubmission;
use App\Models\User;

class AssignmentSubmissionPolicy
{
    public function view(User $user, AssignmentSubmission $submission): bool
    {
        return $submission->user_id === $user->id;
    }

    public function update(User $user, AssignmentSubmission $submission): bool
    {
        return $submission->user_id === $user->id
            && ($submission->graded_at === null || $submission->returned_at !== null);
    }
}
