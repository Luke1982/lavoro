<?php

namespace App\Http\Requests\Landlord;

use App\Rules\Iban;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::guard('landlord')->check();
    }

    public function rules(): array
    {
        return [
            'package_key' => 'nullable|string',
            'subscription_started_on' => 'nullable|date',
            'billing_period' => 'required|in:monthly,yearly',
            'modules' => 'array',

            'invoice_email' => 'nullable|email',
            'invoice_address' => 'nullable|string',
            'invoice_postcode' => 'nullable|string|max:16',
            'invoice_city' => 'nullable|string',
            'vat_number' => 'nullable|string|max:32',
            'coc_number' => 'nullable|string|max:32',

            'payment_method' => 'required|in:transfer,direct_debit',
            'iban' => ['nullable', 'string', 'max:34', new Iban, 'required_if:payment_method,direct_debit'],
            'account_holder' => 'nullable|string|max:70',
            'mandate_reference' => ['nullable', 'string', 'max:35', 'required_if:payment_method,direct_debit'],
            'mandate_signed_on' => ['nullable', 'date', 'required_if:payment_method,direct_debit'],

            'extra_field_seats' => 'required|integer|min:0',
            'extra_office_seats' => 'required|integer|min:0',
            'storage_limit_gb' => 'required|integer|min:0',

            'ai_allowance_euro' => 'nullable|numeric|min:0',
            'price_override_euro' => 'nullable|numeric|min:0',
            'discount_type' => 'required|in:none,euro,percent',
            'discount_euro' => 'nullable|numeric|min:0',
            'discount_percent' => 'nullable|integer|min:0|max:100',
        ];
    }

    /**
     * Het formulier praat in euro's, de tenant rekent in centen. Die vertaling
     * hoort hier en niet in de controller: daar stond hij tussen het opslaan
     * door, met drie losse regels om de velden die alleen op het scherm
     * bestaan er weer uit te halen.
     *
     * @return array<string, mixed>
     */
    public function tenantAttributes(): array
    {
        $data = $this->validated();
        $type = $data['discount_type'];

        return collect($data)
            ->except(['ai_allowance_euro', 'price_override_euro', 'discount_euro', 'discount_percent', 'discount_type'])
            ->merge([
                'modules' => $data['modules'] ?? [],
                'ai_allowance_micros' => $this->scaled('ai_allowance_euro', 1_000_000),
                'price_override_cents' => $this->scaled('price_override_euro', 100),
                /** Een korting is een bedrag of een percentage, nooit allebei. */
                'discount_cents' => $type === 'euro' ? $this->scaled('discount_euro', 100) : null,
                'discount_percent' => $type === 'percent' ? (int) ($data['discount_percent'] ?? 0) : null,
            ])
            ->all();
    }

    /** Leeg blijft leeg: dat betekent "niet ingesteld" en niet "nul". */
    private function scaled(string $field, int $factor): ?int
    {
        $value = $this->validated()[$field] ?? null;

        return ($value === null || $value === '') ? null : (int) round((float) $value * $factor);
    }
}
