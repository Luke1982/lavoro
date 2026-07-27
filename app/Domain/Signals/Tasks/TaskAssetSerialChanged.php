<?php

namespace App\Domain\Signals\Tasks;

use App\Domain\Signals\BaseSignal;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTaskInstance;
use Illuminate\Database\Eloquent\Model;

class TaskAssetSerialChanged extends BaseSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public ServiceOrderTaskInstance $task_instance,
        public string $task_title,
        public ?string $previous_serial,
        public ?string $new_serial,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'taskinstance.asset_serial_changed';
    }

    public static function label(): string
    {
        return 'Serienummer bij taak gewijzigd';
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
        return sprintf(
            'Serienummer bij taak "%s" gewijzigd van %s naar %s',
            $this->task_title,
            $this->previous_serial ?? '-',
            $this->new_serial ?? '-'
        );
    }

    public function changes(): array
    {
        return [[
            'field' => 'task_asset_serial_number',
            'label' => 'Serienummer bij taak',
            'old_value' => $this->previous_serial,
            'new_value' => $this->new_serial,
            'old_label' => $this->previous_serial,
            'new_label' => $this->new_serial,
        ]];
    }
}
