<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\PaymentMethod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PaymentMethodController extends Controller
{
    public function index(): View
    {
        return view('admin.billing.payment-methods.index', [
            'methods' => PaymentMethod::with('courses:id,title')
                ->orderBy('sort_order')->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.billing.payment-methods.form', [
            'method' => null,
            'courses' => Course::orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $method = PaymentMethod::create($this->validated($request));
        $method->courses()->sync($request->input('courses', []));

        return redirect()->route('admin.billing.payment-methods.index')
            ->with('success', "Payment method \"{$method->name}\" added.");
    }

    public function edit(PaymentMethod $paymentMethod): View
    {
        return view('admin.billing.payment-methods.form', [
            'method' => $paymentMethod->load('courses:id'),
            'courses' => Course::orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function update(Request $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        $paymentMethod->update($this->validated($request));
        $paymentMethod->courses()->sync($request->input('courses', []));

        return redirect()->route('admin.billing.payment-methods.index')
            ->with('success', "Payment method \"{$paymentMethod->name}\" updated.");
    }

    public function destroy(PaymentMethod $paymentMethod): RedirectResponse
    {
        $paymentMethod->delete();

        return redirect()->route('admin.billing.payment-methods.index')
            ->with('success', "Payment method \"{$paymentMethod->name}\" removed.");
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'channel' => ['required', Rule::in(array_keys(PaymentMethod::CHANNELS))],
            'account_title' => ['nullable', 'required_unless:channel,cash_deposit', 'string', 'max:120'],
            'account_number' => ['nullable', 'required_unless:channel,cash_deposit', 'string', 'max:60'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'instructions' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'courses' => ['nullable', 'array'],
            'courses.*' => [Rule::exists('courses', 'id')],
        ]);

        // Cash is collected at the counter — there is no account to show.
        if ($data['channel'] === 'cash_deposit') {
            $data['account_title'] = null;
            $data['account_number'] = null;
            $data['bank_name'] = null;
        }

        $data['is_active'] = $request->boolean('is_active');
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        unset($data['courses']);

        return $data;
    }
}
