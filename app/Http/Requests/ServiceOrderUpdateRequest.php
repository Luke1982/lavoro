<?php

namespace App\Http\Requests;

use App\Domain\ServiceOrders\ClosingRequirements;
use App\Domain\Signals\ServiceOrders\ServiceOrderCloseBlockedByHiddenTasks;
use App\Domain\Signals\Signals;
use App\Enums\ServiceOrderTypes;
use App\Models\Asset;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\ServiceOrderTaskInstance;
use App\Rules\ProjectAcceptsWork;
use App\Services\TaskInstanceVisibility;
use Illuminate\Database\Eloquent\Collection;
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
            'signed_by' => 'sometimes|required|string|max:100',
            'signature_base64' => 'sometimes|required|string',
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
            'project_id' => [
                'nullable',
                'exists:projects,id',
                new ProjectAcceptsWork($this->route('serviceorder')->project_id),
            ],
            'order_date' => 'nullable|date',
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
            if (!$this->has('service_order_stage_id')) {
                return;
            }

            $serviceorder = $this->route('serviceorder');
            $new_stage = $this->requestedStage();

            if ($new_stage === null && $serviceorder->is_closed) {
                $validator->errors()->add('service_order_stage_id', 'Er is geen open fase om de werkbon in te heropenen.');

                return;
            }

            if ($new_stage === null || $new_stage->id === $serviceorder->service_order_stage_id) {
                return;
            }

            if (!$this->user()->can('updateStage', [$serviceorder, $new_stage])) {
                $validator->errors()->add('service_order_stage_id', $serviceorder->is_closed && !$new_stage->closesOrder()
                    ? 'Je hebt geen toestemming om een gesloten werkbon te heropenen.'
                    : 'Je hebt geen toestemming om de werkbon naar deze fase te verplaatsen.');

                return;
            }

            $requirements = app(ClosingRequirements::class);
            $unmet = $requirements->unmetFor($serviceorder, $new_stage);

            foreach ($unmet as $problem) {
                $validator->errors()->add($problem['field'], $problem['message']);
            }

            if (collect($unmet)->contains('field', 'open_task_instances')) {
                $this->announceHiddenBlockers($serviceorder, $requirements->openTasks($serviceorder));
            }
        });
    }

    /**
     * The fase this request moves the werkbon to. An empty one is how a closed
     * werkbon is reopened: back to its last open fase, never into no fase at all.
     * On an open werkbon an empty fase changes nothing.
     */
    public function requestedStage(): ?ServiceOrderStage
    {
        return once(function () {
            $serviceorder = $this->route('serviceorder');
            $stage_id = $this->input('service_order_stage_id');

            if ($stage_id === null) {
                return $serviceorder->is_closed ? $serviceorder->reopenStage() : null;
            }

            return ServiceOrderStage::find($stage_id);
        });
    }

    /**
     * Zit er onder de openstaande taken werk dat deze lezer niet eens te zien krijgt, dan
     * loopt hij vast op iets dat voor hem niet bestaat en kan hij er zelf niets aan doen.
     * Dat is een feit op zich, dus het gaat als signaal de deur uit naar wie de bon wel mag
     * bijwerken.
     *
     * Aangekondigd vanuit het weigeren zelf, en niet verderop vanuit de controller: het
     * verzoek strandt hier op de validatie en komt daar nooit aan.
     *
     * @param  Collection<int, ServiceOrderTaskInstance>  $open
     */
    private function announceHiddenBlockers(ServiceOrder $serviceorder, Collection $open): void
    {
        $hidden = $open->whereIn(
            'id',
            app(TaskInstanceVisibility::class)->hiddenFrom($serviceorder, $this->user())->modelKeys()
        );

        if ($hidden->isEmpty()) {
            return;
        }

        Signals::dispatch(new ServiceOrderCloseBlockedByHiddenTasks(
            $serviceorder,
            $hidden->map(fn ($instance) => $instance->effective_title ?: 'Taak zonder titel')->values()->all(),
            $hidden->flatMap->userRoles->pluck('name')->unique()->values()->all(),
            $open->count(),
        ));
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
            'order_date.date' => 'Datum opdracht moet een geldige datum zijn.',
            'closed_on.date' => 'Gesloten op moet een geldige datum zijn.',
            'signed_by.required' => 'Vul de naam in van degene die tekent.',
            'signed_by.max' => 'Ondertekend door mag maximaal 100 tekens bevatten.',
            'signature_base64.required' => 'Er is geen handtekening gezet.',
            'signature_base64.string' => 'Handtekening moet een geldige string zijn.',
            'actual_end_time.after' => 'Eindtijd moet later zijn dan de starttijd.',
        ];
    }
}
