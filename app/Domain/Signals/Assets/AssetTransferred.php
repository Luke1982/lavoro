<?php

namespace App\Domain\Signals\Assets;

use App\Domain\Signals\BaseSignal;
use App\Models\Asset;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;

/**
 * A machine went over to another customer, with every part built into it. Told on
 * the machine and on both customers: the one it left loses it from its history
 * going forward, and that is exactly what someone there will come asking about.
 *
 * Its new location is written by the machine's own trail, as any move is.
 */
class AssetTransferred extends BaseSignal
{
    public function __construct(
        public Asset $asset,
        public ?Customer $previous_customer,
        public ?Customer $new_customer,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'asset.transferred';
    }

    public static function label(): string
    {
        return 'Machine overgedragen';
    }

    public static function coveredFields(): array
    {
        return ['customer_id'];
    }

    public function subject(): Model
    {
        return $this->asset;
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    public function activityDescription(): ?string
    {
        return 'Machine overgedragen van ' . ($this->previous_customer?->name ?? 'onbekend')
            . ' aan ' . ($this->new_customer?->name ?? 'onbekend');
    }

    public function activityContext(): array
    {
        return array_values(array_filter([$this->previous_customer, $this->new_customer]));
    }

    public function activityMetadata(): ?array
    {
        return ['subject_label' => $this->asset->historyLabel()];
    }

    public function changes(): array
    {
        return [[
            'field' => 'customer_id',
            'label' => 'Klant',
            'old_value' => $this->previous_customer?->id === null ? null : (string) $this->previous_customer->id,
            'new_value' => $this->new_customer?->id === null ? null : (string) $this->new_customer->id,
            'old_label' => $this->previous_customer?->name,
            'new_label' => $this->new_customer?->name,
        ]];
    }
}
