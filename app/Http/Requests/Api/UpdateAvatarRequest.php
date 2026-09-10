<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // An image field: 1 MB. `image` already refuses an archive.
            'avatar' => ['required', 'image', 'max:1024'],
        ];
    }
}
