<?php

namespace App\Http\Requests;

use App\Enums\ServiceJobOutcomes;
use App\Models\ServiceJob;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Class ServiceJobUpdateRequest
 * @package App\Http\Requests
 *
 * This class handles the validation for updating a service job.
 *
 * @property string|null $description
 * @property string $outcome
 * @property int|null $days_temporary_approval
 * @property string|null $completed_on
 */
class ServiceJobUpdateRequest extends FormRequest
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
            'description' => 'nullable|string|max:1000',
            'outcome' => 'required|in:' . implode(',', array_map(fn($oc) => $oc->value, ServiceJobOutcomes::cases())),
            'days_temporary_approval' =>
                $this->outcome === ServiceJobOutcomes::tijdelijk_goedkeur->value ? 'required' : 'nullable' .
                '|integer|min:0|max:365',
            'completed_on' => 'required|date',
        ];
    }

    protected function passedValidation(): void
    {
        $job = request()->route('servicejob');
        if (!$job) {
            return;
        }
        $job->loadMissing('checkInstances.serviceCheck', 'checkInstances.values');

        $incomplete = $job->checkInstances->filter(function ($ci) {
            $type = $ci->serviceCheck?->type;
            if ($type === 'boolean') {
                return $ci->switch_state === null;
            }
            if ($type === 'text' || $type === 'number') {
                return trim((string) ($ci->description ?? '')) === '';
            }
            if ($type === 'radio') {
                return $ci->values->count() !== 1;
            }
            if ($type === 'checkgroup') {
                return $ci->values->count() === 0;
            }
            return false;
        });

        if ($incomplete->isNotEmpty()) {
            $names = $incomplete
                ->map(fn($ci) => $ci->serviceCheck?->name)
                ->filter()
                ->unique()
                ->values();
            $list = $names->implode(', ');
            $message = 'Niet alle keurpunten zijn ingevuld (' . $incomplete->count() . ' open): ' . $list . '.';
            throw ValidationException::withMessages([
                'service_checks' => $message,
            ]);
        }
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'description.max' => 'Beschrijving mag maximaal 1000 tekens bevatten.',
            'outcome.required' => 'Resultaat is verplicht.',
            'outcome.in' => 'Ongeldig resultaat opgegeven.',
            'days_temporary_approval.integer' => 'Tijdelijke goedkeuring moet een geheel getal zijn.',
            'days_temporary_approval.min' => 'Tijdelijke goedkeuring moet minimaal 0 dagen zijn.',
            'days_temporary_approval.max' => 'Tijdelijke goedkeuring mag maximaal 365 dagen zijn.',
            'days_temporary_approval.required' => 'Aantal dagen is verplicht wanneer het resultaat '
                . '\'tijdelijk goedkeur\' is.',
            'completed_on.required' => '\'Voltooid op\' datum is verplicht.',
            'completed_on.date' => '\'Voltooid op\' moet een geldige datum zijn.',
        ];
    }
}
