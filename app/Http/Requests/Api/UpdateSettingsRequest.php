<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'timezone' => ['sometimes', 'string', Rule::in(timezone_identifiers_list())],
            'language' => ['sometimes', 'string', 'max:10'],
            'notifications' => ['sometimes', 'array'],
            'notifications.email_announcements' => ['sometimes', 'boolean'],
            'notifications.email_assignment_graded' => ['sometimes', 'boolean'],
            'notifications.email_due_reminders' => ['sometimes', 'boolean'],
            'notifications.email_new_content' => ['sometimes', 'boolean'],
            'notifications.push_announcements' => ['sometimes', 'boolean'],
            'notifications.push_due_reminders' => ['sometimes', 'boolean'],
        ];
    }
}
