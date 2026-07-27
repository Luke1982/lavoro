<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

class ServiceJobEmailed extends BaseSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public ServiceJob $service_job,
        public array $recipients,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.job_emailed';
    }

    public static function label(): string
    {
        return 'Keuring per e-mail verzonden';
    }

    public function activityCategory(): string
    {
        return 'email';
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityDescription(): ?string
    {
        return 'Keuring per e-mail verzonden naar: ' . implode(', ', $this->recipients);
    }
}
