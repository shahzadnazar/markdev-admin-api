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
            // Attachment-style: 5 MB, and STILL required (9bed5dd). No mimes
            // list, so a student can submit a zip of their project — which was
            // already true and is now tested.
            'file' => ['required', 'file', 'max:5120'],
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
