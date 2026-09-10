<?php

namespace App\Http\Requests\Api;

use App\Services\FeeSubmissionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'channel' => ['required_without:payment_method_id', 'nullable', Rule::in(array_keys(FeeSubmissionService::CHANNELS))],
            'payment_method_id' => ['nullable', Rule::exists('payment_methods', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'payer_name' => ['nullable', 'string', 'max:120'],
            'reference_no' => ['nullable', 'string', 'max:120'],
            'payment_date' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
            // ONE limit for the whole field, not 1 MB for a JPG and 5 for a
            // PDF. This is an evidence field that happens to take images, and
            // its commonest input is a phone photo of a bank slip — 2-4 MB
            // straight off the camera — so a 1 MB image rule here would refuse
            // most real receipts. 5 MB for everything it accepts. No archives:
            // the mimes list decides that and is unchanged.
            'receipt' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,pdf', 'max:5120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'channel.required_without' => 'Choose the account you paid into.',
            'payment_date.before_or_equal' => 'The payment date cannot be in the future.',
            'receipt.required' => 'Attach your payment receipt (PNG, JPG, WEBP or PDF, max 5MB).',
            'receipt.max' => 'The receipt must be 5MB or smaller.',
        ];
    }
}
