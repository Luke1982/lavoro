<?php

namespace App\Domain\Signals\Tasks;

use App\Domain\Signals\BaseSignal;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTaskInstance;
use Illuminate\Database\Eloquent\Model;

class TaskAssetsRegistered extends BaseSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public ServiceOrderTaskInstance $task_instance,
        public string $task_title,
        public array $serial_numbers,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'taskinstance.assets_registered';
    }

    public static function label(): string
    {
        return 'Apparatuur geregistreerd bij taak';
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
        return sprintf('Apparatuur geregistreerd bij taak "%s": %s', $this->task_title, implode(', ', $this->serial_numbers));
    }
}
