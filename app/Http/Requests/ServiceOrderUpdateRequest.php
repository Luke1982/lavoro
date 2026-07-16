<?php

namespace App\Http\Requests;

use App\Enums\ServiceOrderTypes;
use App\Models\Asset;
use App\Models\GeneralSetting;
use App\Models\ServiceOrderStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

    /**
     * A linked location is the source of truth for where the work happens, so a
     * selected location and a free-text execution_location can never coexist.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('location_id')) {
            $this->merge(['execution_location' => null]);
        }
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
            'work_completed' => 'sometimes|boolean',
        ];

        if (!$this->user()->can('update', $this->route('serviceorder'))) {
            return $completion_rules;
        }

        $update_rules = [
            'customer_id' => 'required|exists:customers,id',
            'location_id' => [
                'nullable',
                Rule::exists('locations', 'id')->where(fn ($q) => $q->where('customer_id', $this->input('customer_id'))),
            ],
            'closed_on' => 'nullable|date',
            'external_purchaseorder_no' => 'nullable|string|max:255',
            'external_invoice_no' => 'nullable|string|max:255',
            'execution_location' => 'nullable|string|max:255',
            'type' => 'nullable|in:' . implode(',', array_column(ServiceOrderTypes::cases(), 'value')),
        ];

        if ($this->user()->can('seeFinancials', $this->route('serviceorder'))) {
            $update_rules['financial_comments'] = 'nullable|string';
        }

        return array_merge($completion_rules, $update_rules, $this->customerChangeRules());
    }

    /**
     * A werkbon whose jobs sit on machines the new customer does not own would be billed
     * to one customer while listing another's machines — and the PDF mails those serial
     * numbers to the new customer. So the caller has to say what happens to the machines.
     * Enforced here rather than only in the modal, so the endpoint alone cannot recreate
     * the leak.
     *
     * @return array<string, array<int, mixed>>
     */
    private function customerChangeRules(): array
    {
        if (!$this->changesCustomerAwayFromItsMachines()) {
            return [];
        }

        return [
            'asset_strategy' => ['required', 'in:transfer'],
            'location_map' => ['nullable', 'array'],
            'location_map.*' => [
                'nullable',
                'integer',
                Rule::exists('locations', 'id')->where(
                    fn ($query) => $query->where('customer_id', $this->input('customer_id'))
                ),
            ],
        ];
    }

    private function changesCustomerAwayFromItsMachines(): bool
    {
        $serviceorder = $this->route('serviceorder');

        if (!$serviceorder || !$this->has('customer_id')) {
            return false;
        }

        $new_customer_id = (int) $this->input('customer_id');

        if ($new_customer_id === (int) $serviceorder->customer_id) {
            return false;
        }

        return $serviceorder->serviceJobs()
            ->with('asset')
            ->get()
            ->pluck('asset')
            ->filter()
            ->contains(fn (Asset $asset) => $asset->resolvedCustomerId() !== $new_customer_id);
    }

    public function withValidator($validator): void
    {
        $this->guardWorkCompleted($validator);

        $validator->after(function ($validator) {
            $new_stage = ServiceOrderStage::find($this->input('service_order_stage_id'));

            if (!$new_stage) {
                return;
            }

            $serviceorder = $this->route('serviceorder');

            if ($new_stage->id === $serviceorder->service_order_stage_id) {
                return;
            }

            if (!$this->user()->can('updateStage', [$serviceorder, $new_stage])) {
                $validator->errors()->add(
                    'service_order_stage_id',
                    'Je hebt geen toestemming om de werkbon naar deze fase te verplaatsen.'
                );

                return;
            }

            if (!$new_stage->is_closed_state) {
                return;
            }

            $incomplete = $serviceorder->taskInstances()
                ->where('is_complete', false)
                ->where('is_cancelled', false)
                ->count();
            if ($incomplete > 0) {
                $validator->errors()->add(
                    'service_order_stage_id',
                    "Er zijn nog {$incomplete} taken niet afgerond of geannuleerd. " .
                    'Rond alle taken af of annuleer ze voordat je de werkbon sluit.'
                );
            }

            $min = (int) GeneralSetting::get('serviceorder_min_images', 0);
            if ($min > 0) {
                $count = $serviceorder->images()->count();
                if ($count < $min) {
                    $message = "Er zijn minimaal {$min} foto's vereist om de werkbon te sluiten."
                        . " Er zijn er {$count} toegevoegd.";
                    $validator->errors()->add('service_order_stage_id', $message);
                }
            }

            if (blank($serviceorder->signed_by) || blank($serviceorder->signature_base64)) {
                $validator->errors()->add(
                    'service_order_stage_id',
                    'De werkbon moet ondertekend zijn door de klant voordat deze gesloten kan worden.'
                );
            }
        });
    }

    /**
     * Whether the work on site is finished is a record of what the engineer found, so it
     * freezes along with the rest of the werkbon once it closes. The stage change that
     * closes the order may still carry it, since the order is only closed after this.
     */
    private function guardWorkCompleted($validator): void
    {
        $validator->after(function ($validator) {
            if (!$this->has('work_completed')) {
                return;
            }

            $serviceorder = $this->route('serviceorder');

            if (!$serviceorder->is_closed) {
                return;
            }

            if ($this->boolean('work_completed') === (bool) $serviceorder->work_completed) {
                return;
            }

            $validator->errors()->add(
                'work_completed',
                'De werkbon is gesloten. Je kunt niet meer wijzigen of de werkzaamheden gereed zijn.'
            );
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
