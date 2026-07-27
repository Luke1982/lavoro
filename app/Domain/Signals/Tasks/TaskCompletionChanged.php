<?php

namespace App\Domain\Signals\Tasks;

use App\Domain\Signals\BaseSignal;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTaskInstance;
use Illuminate\Database\Eloquent\Model;

class TaskCompletionChanged extends BaseSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public ServiceOrderTaskInstance $task_instance,
        public string $task_title,
        public bool $is_complete,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'taskinstance.completion_changed';
    }

    public static function label(): string
    {
        return 'Taakstatus gewijzigd';
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
        return sprintf('Taak "%s" %s', $this->task_title, $this->is_complete ? 'afgerond' : 'heropend');
    }
}
