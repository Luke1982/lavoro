<?php

namespace App\Http\Requests;

use App\Enums\ServiceOrderTypes;
use App\Models\ServiceOrderStage;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Class ServiceOrderUpdateRequest
 *
 * @property int $customer_id
 * @property string|null $description
 * @property string|null $signed_by
 * @property string|null $signature_base64
 */
class ServiceOrderUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('serviceorder'))
            || $this->user()->can('complete', $this->route('serviceorder'));
    }

    public function rules(): array
    {
        $completion_rules = [
            'description' => 'nullable|string|max:1000',
            'signed_by' => 'nullable|string|max:100',
            'signature_base64' => 'nullable|string',
            'actual_start_time' => 'nullable|date_format:H:i',
            'actual_end_time' => 'nullable|date_format:H:i|after:actual_start_time',
            'service_order_stage_id' => 'nullable|exists:service_order_stages,id',
        ];

        if (! $this->user()->can('update', $this->route('serviceorder'))) {
            return $completion_rules;
        }

        return array_merge($completion_rules, [
            'customer_id' => 'required|exists:customers,id',
            'closed_on' => 'nullable|date',
            'external_purchaseorder_no' => 'nullable|string|max:255',
            'external_invoice_no' => 'nullable|string|max:255',
            'execution_location' => 'nullable|string|max:255',
            'type' => 'nullable|in:' . implode(',', array_column(ServiceOrderTypes::cases(), 'value')),
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $new_stage = ServiceOrderStage::find($this->input('service_order_stage_id'));

            if (! $new_stage) {
                return;
            }

            $serviceorder = $this->route('serviceorder');

            if ($new_stage->id === $serviceorder->service_order_stage_id) {
                return;
            }

            if (! $this->user()->can('updateStage', [$serviceorder, $new_stage])) {
                $validator->errors()->add(
                    'service_order_stage_id',
                    'Je hebt geen toestemming om de werkbon naar deze fase te verplaatsen.'
                );

                return;
            }

            if (! $new_stage->is_closed_state) {
                return;
            }

            $incomplete = $serviceorder->taskInstances()->where('is_complete', false)->count();
            if ($incomplete > 0) {
                $validator->errors()->add(
                    'service_order_stage_id',
                    "Er zijn nog {$incomplete} taken niet afgerond. Rond alle taken af voordat je de werkbon sluit."
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => 'Klant is verplicht.',
            'customer_id.exists' => 'De opgegeven klant bestaat niet.',
            'description.max' => 'Beschrijving mag maximaal 1000 tekens bevatten.',
            'closed_on.date' => 'Gesloten op moet een geldige datum zijn.',
            'signed_by.max' => 'Ondertekend door mag maximaal 100 tekens bevatten.',
            'signature_base64.string' => 'Handtekening moet een geldige string zijn.',
            'actual_end_time.after' => 'Eindtijd moet later zijn dan de starttijd.',
        ];
    }
}
