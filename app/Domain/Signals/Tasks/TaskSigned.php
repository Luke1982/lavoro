<?php

namespace App\Domain\Signals\Tasks;

use App\Domain\Signals\BaseSignal;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTaskInstance;
use Illuminate\Database\Eloquent\Model;

class TaskSigned extends BaseSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public ServiceOrderTaskInstance $task_instance,
        public string $task_title,
        public ?string $signed_by,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'taskinstance.signed';
    }

    public static function label(): string
    {
        return 'Taak ondertekend';
    }

    public function activityCategory(): string
    {
        return 'status';
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityDescription(): ?string
    {
        return sprintf('Taak "%s" ondertekend door %s', $this->task_title, $this->signed_by ?? 'onbekend');
    }
}
