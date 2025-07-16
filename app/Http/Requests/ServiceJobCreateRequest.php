<?php

namespace App\Http\Requests;

use App\Enums\ServiceJobOutcomes;
use Illuminate\Foundation\Http\FormRequest;

class ServiceJobCreateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'service_order_id' => 'required|exists:service_orders,id',
            'description' => 'nullable|string|max:1000',
            'asset_id' => 'required|exists:assets,id',
            'outcome' => 'required|in:' . implode(',', array_map(fn($oc) => $oc->value, ServiceJobOutcomes::cases())),
            'days_temporary_approval' => 'nullable|integer|min:0|max:365',
            'completed_on' => 'nullable|date',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'service_order_id.required' => 'Werkbon is verplicht.',
            'service_order_id.exists' => 'De opgegeven werkbon bestaat niet.',
            'description.max' => 'Beschrijving mag maximaal 1000 tekens bevatten.',
            'asset_id.required' => 'Asset is verplicht.',
            'asset_id.exists' => 'Het opgegeven asset bestaat niet.',
            'outcome.required' => 'Resultaat is verplicht.',
            'outcome.in' => 'Ongeldig resultaat opgegeven.',
            'days_temporary_approval.integer' => 'Tijdelijke goedkeuring moet een geheel getal zijn.',
            'days_temporary_approval.min' => 'Tijdelijke goedkeuring moet minimaal 0 dagen zijn.',
            'days_temporary_approval.max' => 'Tijdelijke goedkeuring mag maximaal 365 dagen zijn.',
            'completed_on.date' => 'Voltooid op moet een geldige datum zijn.',
        ];
    }
}
