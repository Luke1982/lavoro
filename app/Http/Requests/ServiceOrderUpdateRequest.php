<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Enums\ServiceOrderTypes;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;

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
        $user = Auth::user();
        $serviceorder = request()->route('serviceorder');
        if (!$user || !$serviceorder instanceof ServiceOrder) {
            return true;
        }
        if (!$this->has('service_order_stage_id')) {
            return true;
        }

        $new_stage_id = $this->input('service_order_stage_id');

        if ($new_stage_id === $serviceorder->service_order_stage_id) {
            return true;
        }

        $new_stage = $new_stage_id === null
            ? null
            : ServiceOrderStage::find($new_stage_id);

        return $user->can('changeStage', [$serviceorder, $new_stage]);
    }

    public function rules(): array
    {
        return [
            'customer_id' => 'required|exists:customers,id',
            'description' => 'nullable|string|max:1000',
            'closed_on' => 'nullable|date',
            'signed_by' => 'nullable|string|max:100',
            'signature_base64' => 'nullable|string',
            'external_purchaseorder_no' => 'nullable|string|max:255',
            'actual_start_time' => 'nullable|date_format:H:i',
            'actual_end_time' => 'nullable|date_format:H:i|after:actual_start_time',
            'service_order_stage_id' => 'nullable|exists:service_order_stages,id',
            'type' => 'nullable|in:' . implode(',', array_column(ServiceOrderTypes::cases(), 'value')),
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $serviceorder = request()->route('serviceorder');
            if (!$serviceorder instanceof ServiceOrder) {
                return;
            }

            $new_stage_id = $this->input('service_order_stage_id');
            if (!$new_stage_id || $new_stage_id == $serviceorder->service_order_stage_id) {
                return;
            }

            $new_stage = ServiceOrderStage::find($new_stage_id);
            if (!$new_stage?->is_closed_state) {
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
