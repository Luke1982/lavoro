<?php

namespace App\Domain\Signals\Tasks;

use App\Domain\Signals\BaseSignal;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTaskInstance;
use App\Models\ServiceOrderTaskInstanceCheck;
use Illuminate\Database\Eloquent\Model;

/**
 * A check on a werkbon task was ticked off or ticked back open. It appears on
 * the werkbon and on the customer, so "who cleaned the grille on machine 2"
 * has an answer.
 */
class TaskCheckChanged extends BaseSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public ServiceOrderTaskInstance $task_instance,
        public string $task_title,
        public ServiceOrderTaskInstanceCheck $check,
        public ?string $machine_label,
        public bool $is_done,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'taskinstance.check_changed';
    }

    public static function label(): string
    {
        return 'Check gewijzigd';
    }

    public function activityCategory(): string
    {
        return 'status';
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityContext(): array
    {
        return array_values(array_filter([$this->service_order->customer]));
    }

    public function activityDescription(): ?string
    {
        return sprintf(
            'Check "%s" %s bij taak "%s"',
            $this->check->title,
            $this->is_done ? 'afgevinkt' : 'uitgevinkt',
            $this->task_title,
        ) . ($this->machine_label ? ', ' . lcfirst($this->machine_label) : '');
    }

    public function changes(): array
    {
        return [[
            'field' => 'is_done',
            'label' => $this->check->title,
            'old_value' => $this->is_done ? '0' : '1',
            'new_value' => $this->is_done ? '1' : '0',
            'old_label' => $this->is_done ? 'Open' : 'Afgevinkt',
            'new_label' => $this->is_done ? 'Afgevinkt' : 'Open',
        ]];
    }
}
