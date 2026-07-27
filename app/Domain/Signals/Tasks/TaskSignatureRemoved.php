<?php

namespace App\Domain\Signals\Tasks;

use App\Domain\Signals\BaseSignal;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTaskInstance;
use Illuminate\Database\Eloquent\Model;

class TaskSignatureRemoved extends BaseSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public ServiceOrderTaskInstance $task_instance,
        public string $task_title,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'taskinstance.signature_removed';
    }

    public static function label(): string
    {
        return 'Handtekening verwijderd';
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
        return sprintf('Handtekening verwijderd bij taak "%s"', $this->task_title);
    }
}
