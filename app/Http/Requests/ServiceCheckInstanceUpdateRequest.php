<?php

namespace App\Http\Requests;

use App\Models\ServiceCheckInstance;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @method \App\Models\User user($guard = null)
 */
class ServiceCheckInstanceUpdateRequest extends FormRequest
{
    /** Filling in a keurpunt is changing its keuring. */
    public function authorize(): bool
    {
        $service_job = $this->route('servicecheckinstance')?->serviceJob;

        return $service_job !== null && $this->user()->can('update', $service_job);
    }

    public function rules(): array
    {
        $instance = request()->route('servicecheckinstance');
        if ($instance instanceof ServiceCheckInstance) {
            $instance->loadMissing('serviceCheck');
        }
        $type = $instance?->serviceCheck?->type ?? request()->get('type');

        $description_rule = 'nullable';
        if ($type === 'text') {
            $description_rule = 'nullable|string|max:255';
        } elseif ($type === 'number') {
            $description_rule = 'nullable|numeric';
        }

        return [
            'values' => 'nullable',
            'description' => $description_rule,
            'switch_state' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'description.string' => 'Waarde moet tekst zijn.',
            'description.numeric' => 'Waarde moet een getal zijn.',
            'description.max' => 'Waarde mag maximaal 255 tekens bevatten.',
            'switch_state.boolean' => 'Ongeldige schakelaarwaarde.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $first = collect($validator->errors()->all())->first();
        if ($first) {
            session()->flash('error', $first);
        }
        parent::failedValidation($validator);
    }
}
