<?php

namespace App\Http\Requests;

use App\Enums\ServiceJobOutcomes;
use App\Models\Asset;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Class ServiceJobCreateRequest
 *
 * @property int $service_order_id
 * @property int $asset_id
 * @property string $outcome
 * @property int|null $days_temporary_approval
 * @property string|null $completed_on
 *
 * @method mixed input(string $key, mixed $default = null)
 * @method \App\Models\User user($guard = null)
 */
class ServiceJobCreateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request: on a werkbon that
     * exists and that this person may open, named by its id and nothing else.
     */
    public function authorize(): bool
    {
        $service_order_id = filter_var($this->input('service_order_id'), FILTER_VALIDATE_INT);
        $service_order = $service_order_id === false ? null : ServiceOrder::find($service_order_id);

        return $service_order !== null
            && $this->user()->can('create', ServiceJob::class)
            && $this->user()->can('view', $service_order);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'service_order_id' => 'required|integer|exists:service_orders,id',
            'description' => 'nullable|string|max:1000',
            'asset_id' => ['required', 'integer', 'exists:assets,id', function ($attribute, $value, $fail) {
                $asset = Asset::find($value);
                $service_order = ServiceOrder::find($this->integer('service_order_id'));

                if ($asset === null || $service_order === null) {
                    return;
                }

                if (!$service_order->isForTheCustomerOf($asset)) {
                    $fail('Deze machine hoort bij een andere klant dan werkbon ' . $service_order->number . '.');
                } elseif ($this->user()->cannot('view', $asset)) {
                    $fail('Deze machine kun je niet openen.');
                }
            }],
            'outcome' => 'required|in:' . implode(',', array_map(fn ($oc) => $oc->value, ServiceJobOutcomes::cases())),
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
            'service_order_id.integer' => 'Werkbon is ongeldig.',
            'service_order_id.exists' => 'De opgegeven werkbon bestaat niet.',
            'description.max' => 'Beschrijving mag maximaal 1000 tekens bevatten.',
            'asset_id.required' => 'Asset is verplicht.',
            'asset_id.integer' => 'Het opgegeven asset is ongeldig.',
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
