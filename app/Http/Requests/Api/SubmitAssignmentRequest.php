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
            'content' => ['nullable', 'string', 'max:65000', 'required_without:file'],
            'file' => ['nullable', 'file', 'max:10240', 'required_without:content'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'content.required_without' => 'Provide written content or attach a file.',
            'file.required_without' => 'Provide written content or attach a file.',
        ];
    }
}
