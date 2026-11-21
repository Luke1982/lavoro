<?php

namespace App\Domain\Signals\Assets;

use App\Domain\Signals\BaseSignal;
use App\Models\Asset;
use App\Models\ProductRelation;
use Illuminate\Database\Eloquent\Model;

/**
 * A standalone machine now hangs under another one as a part. Its customer column
 * is emptied in the process, but its customer stays the same: from now on it
 * belongs to them through its parent, which nobody would call a change of owner.
 * Its location is written by its own trail.
 */
class AssetAttachedToParent extends BaseSignal
{
    public function __construct(
        public Asset $asset,
        public Asset $parent,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'asset.attached_to_parent';
    }

    public static function label(): string
    {
        return 'Machine gekoppeld onder een andere machine';
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

    /** Names both machines, so it reads the same on the part, on the machine it went into and on the customer. */
    public function activityDescription(): ?string
    {
        return 'Onderdeel gekoppeld: ' . $this->asset->historyLabel() . ' onder ' . $this->parent->historyLabel();
    }

    /** On the machine it went into and on the customer, under this machine's name. */
    public function activityContext(): array
    {
        return array_values(array_filter([$this->parent, $this->parent->resolvedCustomer()]));
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
            'old_value' => null,
            'new_value' => (string) $this->parent->id,
            'old_label' => null,
            'new_label' => $this->parent->historyLabel(),
        ]];

        if ($this->asset->product_relation_id !== null) {
            $changes[] = [
                'field' => 'product_relation_id',
                'label' => 'Productrelatie',
                'old_value' => null,
                'new_value' => (string) $this->asset->product_relation_id,
                'old_label' => null,
                'new_label' => ProductRelation::find($this->asset->product_relation_id)?->name,
            ];
        }

        return $changes;
    }
}
