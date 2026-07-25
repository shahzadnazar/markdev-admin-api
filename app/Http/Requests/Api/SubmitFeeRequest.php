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
            'payer_name' => ['required', 'string', 'max:120'],
            'reference_no' => ['required', 'string', 'max:120'],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
            'receipt' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,pdf', 'max:5120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'channel.required' => 'Choose the account you paid into.',
            'payer_name.required' => 'Enter the name used on the payment.',
            'reference_no.required' => 'Enter the bank/wallet transaction reference.',
            'payment_date.required' => 'Tell us when you made the payment.',
            'payment_date.before_or_equal' => 'The payment date cannot be in the future.',
            'receipt.required' => 'Attach your payment receipt (PNG, JPG or PDF, max 5MB).',
            'receipt.max' => 'The receipt must be 5MB or smaller.',
        ];
    }
}
