<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

class ServiceOrderEmailed extends BaseSignal implements ServiceOrderSignal
{
    /** @param array<int, string> $recipients */
    public function __construct(
        public ServiceOrder $service_order,
        public array $recipients,
        public bool $with_jobs = false,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.emailed';
    }

    public static function label(): string
    {
        return 'Werkbon per e-mail verzonden';
    }

    public function serviceOrder(): ServiceOrder
    {
        return $this->service_order;
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityDescription(): ?string
    {
        $what = $this->with_jobs ? 'Werkbon + keuringen' : 'Werkbon';

        return $what . ' per e-mail verzonden naar: ' . implode(', ', $this->recipients);
    }

    public function activityCategory(): string
    {
        return 'email';
    }

    public function activityMetadata(): ?array
    {
        return ['recipients' => $this->recipients, 'with_jobs' => $this->with_jobs];
    }
}
