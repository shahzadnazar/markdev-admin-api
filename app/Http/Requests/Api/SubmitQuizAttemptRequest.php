<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SubmitQuizAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'answers' => ['present', 'array'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.selected_option_ids' => ['sometimes', 'array'],
            'answers.*.selected_option_ids.*' => ['integer'],
            'answers.*.answer_text' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
