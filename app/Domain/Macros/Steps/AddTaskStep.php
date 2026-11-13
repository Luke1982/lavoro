<?php

namespace App\Domain\Macros\Steps;

use App\Actions\Tasks\CreateTaskInstanceAction;
use App\Domain\Macros\Asked;
use App\Domain\Macros\MacroRun;
use App\Domain\Macros\MacroStep;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTask;
use App\Models\User;
use App\Services\TaskChecks\PickedExtras;

/** Puts a task from a template on the werkbon, with its checks, as the task form would. */
class AddTaskStep implements MacroStep
{
    public function __construct(private readonly CreateTaskInstanceAction $create) {}

    public static function type(): string
    {
        return 'add_task';
    }

    public function rules(): array
    {
        return [
            'service_order_task_id' => ['required', 'integer', 'exists:service_order_tasks,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'checks_per_unit' => ['boolean'],
            'extra_check_ids' => ['array'],
            'extra_check_ids.*' => ['integer', 'exists:service_order_task_checks,id'],
            'extra_checklist_ids' => ['array'],
            'extra_checklist_ids.*' => ['integer', 'exists:service_order_task_checklists,id'],
            'extra_titles' => ['array'],
            'extra_titles.*' => ['string', 'max:255'],
        ];
    }

    public function askable(): array
    {
        return ['quantity' => 'number'];
    }

    public function describe(array $step): string
    {
        $quantity = $step['quantity'] ?? 1;

        return 'Taak toevoegen: ' . (ServiceOrderTask::find($step['service_order_task_id'] ?? null)?->title ?? 'onbekend sjabloon')
            . (($product = Product::find($step['product_id'] ?? null)) ? ', ' . $product->display_name : '')
            . ', aantal ' . (Asked::is($quantity) ? Asked::quoted($quantity) : $quantity)
            . (!empty($step['checks_per_unit']) ? ', checks per machine' : '')
            . '.';
    }

    public function allows(User $user): bool
    {
        return $user->can('addTask', ServiceOrder::class);
    }

    public function run(ServiceOrder $order, array $step, MacroRun $run): void
    {
        $quantity = (int) Asked::value($step, 'quantity', $run);

        $this->create->execute([
            'service_order_id' => $order->id,
            'service_order_task_id' => $step['service_order_task_id'],
            'product_id' => $step['product_id'] ?? null,
            'quantity' => $quantity,
            'checks_per_unit' => !empty($step['checks_per_unit']) && $quantity > 1 && !empty($step['product_id']),
        ], new PickedExtras($step['extra_check_ids'] ?? [], $step['extra_checklist_ids'] ?? [], $step['extra_titles'] ?? []));
    }
}
