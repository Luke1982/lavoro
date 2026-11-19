<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReadsTaskInstanceForm;
use App\Http\Requests\Concerns\ValidatesFlexQuantities;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTask;
use App\Services\TaskChecks\TaskCheckCatalogue;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ServiceOrderTaskInstanceStoreRequest extends FormRequest
{
    use ReadsTaskInstanceForm;
    use ValidatesFlexQuantities;

    /** A werkbon that does not exist is left to the validation, which says so. */
    public function authorize(): Response
    {
        $service_order = ServiceOrder::find($this->input('service_order_id'));

        return $service_order
            ? Gate::inspect('addTaskTo', $service_order)
            : Gate::inspect('addTask', ServiceOrder::class);
    }

    public function rules(): array
    {
        return array_merge([
            'service_order_id' => ['required', 'integer', 'exists:service_orders,id'],
            'service_order_task_id' => ['nullable', 'integer', 'exists:service_order_tasks,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_complete' => ['sometimes', 'boolean'],
            'user_role_ids' => ['sometimes', 'array'],
            'user_role_ids.*' => ['integer', 'exists:user_roles,id'],
            'checks_per_unit' => ['sometimes', 'boolean'],
        ], $this->extraCheckRules(), $this->flexQuantityRules());
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if (empty($this->service_order_task_id) && empty($this->description)) {
                $v->errors()->add('description', 'Vul een omschrijving in of kies een bestaande taak.');
            }

            if ($this->boolean('is_complete') && $this->hasRequiredChecks()) {
                $v->errors()->add(
                    'is_complete',
                    'Deze taak heeft verplichte checks en kan niet als voltooid worden aangemaakt.'
                );
            }

            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $this->validateFlexQuantities(
                $v,
                Product::with('productables.childProduct')->find($this->input('product_id'))
            );
        });
    }

    private function hasRequiredChecks(): bool
    {
        $task = ServiceOrderTask::find($this->input('service_order_task_id'));

        return app(TaskCheckCatalogue::class)->forTemplate($task)->contains('is_required', true);
    }
}
