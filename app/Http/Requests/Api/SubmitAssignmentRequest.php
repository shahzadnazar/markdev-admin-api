<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SubmitAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Legacy submission body — still accepted for older clients.
            'content' => ['nullable', 'string', 'max:65000'],
            // Student's question to the instructor.
            'query' => ['nullable', 'string', 'max:65000'],
            'file' => ['required', 'file', 'max:10240'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'Attach your work to submit.',
        ];
    }
}
