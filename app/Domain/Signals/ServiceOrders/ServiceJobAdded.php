<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\Asset;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

class ServiceJobAdded extends BaseSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public Asset $asset,
        public bool $combined = false,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.job_added';
    }

    public static function label(): string
    {
        return 'Keuring toegevoegd aan werkbon';
    }

    public function activityCategory(): string
    {
        return 'created';
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityDescription(): ?string
    {
        return sprintf(
            '%s: %s %s %s (serienummer %s)',
            $this->combined ? 'Gecombineerde keuring toegevoegd voor onderdeel' : 'Keuring toegevoegd',
            $this->asset->product?->productType?->name ?? 'Onbekend type',
            $this->asset->product?->brand?->name ?? '',
            $this->asset->product?->model ?? '',
            $this->asset->serial_number ?? '-'
        );
    }
}
