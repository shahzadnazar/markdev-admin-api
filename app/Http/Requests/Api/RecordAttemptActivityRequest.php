<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cumulative away totals for one in-progress attempt.
 *
 * The numbers here are the client's running totals for the attempt, not a
 * delta since the last message. Nothing is trusted: QuizAttempt::
 * recordAwayTotals caps both against the attempt's own elapsed time and only
 * ever moves them upward. These rules exist to keep obvious rubbish out of the
 * validator's reach, not to establish that the values are true.
 */
class RecordAttemptActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is behind auth:sanctum and the controller runs the policy.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'away_count' => ['required', 'integer', 'min:0'],
            'away_seconds' => ['required', 'integer', 'min:0'],
        ];
    }
}
