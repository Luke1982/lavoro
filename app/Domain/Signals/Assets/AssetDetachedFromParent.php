<?php

namespace App\Domain\Signals\Assets;

use App\Domain\Signals\BaseSignal;
use App\Models\Asset;
use App\Models\Productable;
use App\Models\ProductRelation;
use Illuminate\Database\Eloquent\Model;

/**
 * A part was taken out of the machine it hung under and stands on its own again,
 * owned by the customer its parent belonged to: that customer column is a new
 * place for the same owner, not a new owner. Its location is written by its own
 * trail.
 */
class AssetDetachedFromParent extends BaseSignal
{
    public function __construct(
        public Asset $asset,
        public Asset $previous_parent,
        public ?int $previous_product_relation_id = null,
        public ?int $previous_productable_id = null,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'asset.detached_from_parent';
    }

    public static function label(): string
    {
        return 'Machine losgekoppeld van een andere machine';
    }

    public static function coveredFields(): array
    {
        return ['parent_asset_id', 'customer_id', 'product_relation_id', 'productable_id'];
    }

    public function subject(): Model
    {
        return $this->asset;
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    /** Names both machines, so it reads the same on the part, on the machine it came out of and on the customer. */
    public function activityDescription(): ?string
    {
        return 'Onderdeel losgekoppeld: ' . $this->asset->historyLabel()
            . ' uit ' . $this->previous_parent->historyLabel();
    }

    /** On the machine it came out of and on the customer, under this machine's name. */
    public function activityContext(): array
    {
        return array_values(array_filter([$this->previous_parent, $this->asset->resolvedCustomer()]));
    }

    public function activityMetadata(): ?array
    {
        return ['subject_label' => $this->asset->historyLabel()];
    }

    public function changes(): array
    {
        $changes = [[
            'field' => 'parent_asset_id',
            'label' => 'Bovenliggende machine',
            'old_value' => (string) $this->previous_parent->id,
            'new_value' => null,
            'old_label' => $this->previous_parent->historyLabel(),
            'new_label' => null,
        ]];

        if ($this->previous_product_relation_id !== null) {
            $changes[] = [
                'field' => 'product_relation_id',
                'label' => 'Productrelatie',
                'old_value' => (string) $this->previous_product_relation_id,
                'new_value' => null,
                'old_label' => ProductRelation::find($this->previous_product_relation_id)?->name,
                'new_label' => null,
            ];
        }

        if ($this->previous_productable_id !== null) {
            $changes[] = [
                'field' => 'productable_id',
                'label' => 'Gekoppeld product',
                'old_value' => (string) $this->previous_productable_id,
                'new_value' => null,
                'old_label' => Productable::find($this->previous_productable_id)?->childProduct?->display_name,
                'new_label' => null,
            ];
        }

        return $changes;
    }
}
