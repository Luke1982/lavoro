<?php

namespace App\Services;

use App\Domain\Signals\Materials\MaterialStockRestored;
use App\Domain\Signals\ServiceOrders\FreeformMaterialAdded;
use App\Domain\Signals\ServiceOrders\FreeformMaterialQuantityChanged;
use App\Domain\Signals\ServiceOrders\FreeformMaterialRemoved;
use App\Domain\Signals\ServiceOrders\FreeformMaterialUnforeseenFlagChanged;
use App\Domain\Signals\ServiceOrders\MaterialAttachedToOrder;
use App\Domain\Signals\ServiceOrders\MaterialDetachedFromOrder;
use App\Domain\Signals\ServiceOrders\MaterialQuantityChanged;
use App\Domain\Signals\ServiceOrders\MaterialUnforeseenFlagChanged;
use App\Domain\Signals\Signals;
use App\Models\FreeformMaterial;
use App\Models\Material;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTaskInstance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Booking a material against an owner moves stock and writes activity, so every path that
 * touches the materiables pivot goes through here rather than the relation.
 *
 * The owner is anything using HasMaterials. Activity always lands on the service order, so
 * a task instance's materials read back in the order's history with the task named.
 */
class MateriableService
{
    public function attach(Model $owner, Material $material, array $attributes): void
    {
        $owner->materials()->attach($material, [
            'quantity' => $attributes['quantity'],
            'unforseen' => $attributes['unforseen'] ?? false,
        ]);
        $material->decrement('stock', $attributes['quantity']);

        $this->announce($owner, fn ($order, $task) => new MaterialAttachedToOrder(
            $order,
            $material->name,
            (float) $attributes['quantity'],
            $task,
            [$material],
        ));
    }

    public function detach(Model $owner, string $materiable_id): void
    {
        $pivot_query = $owner->materials()
            ->newPivotQuery()
            ->where('materiables.id', $materiable_id);

        $record = $pivot_query->first();
        $material = $record ? Material::find($record->material_id) : null;
        $quantity = $record ? (float) $record->quantity : 0;

        $pivot_query->delete();

        if (!$material) {
            return;
        }

        $material->increment('stock', $quantity);
        $this->announce($owner, fn ($order, $task) => new MaterialDetachedFromOrder(
            $order,
            $material->name,
            $task,
            [$material],
        ));
    }

    public function update(Model $owner, string $materiable_id, array $attributes): void
    {
        $pivot_query = $owner->materials()
            ->newPivotQuery()
            ->where('materiables.id', $materiable_id);

        $record = $pivot_query->first();
        $material = $record ? Material::find($record->material_id) : null;

        $pivot_query->update($attributes);

        if (!$material) {
            return;
        }

        if (array_key_exists('quantity', $attributes)) {
            $old_quantity = $record->quantity !== null ? (float) $record->quantity : null;
            $new_quantity = (float) $attributes['quantity'];
            $delta = $old_quantity !== null ? $new_quantity - $old_quantity : null;

            if ($delta !== null && $delta !== 0.0) {
                $material->decrement('stock', $delta);
            }

            $this->announce($owner, fn ($order, $task) => new MaterialQuantityChanged(
                $order,
                $material->name,
                $old_quantity,
                $new_quantity,
                $task,
                [$material],
            ));
        }

        if (array_key_exists('unforseen', $attributes)) {
            $this->announce($owner, fn ($order, $task) => new MaterialUnforeseenFlagChanged(
                $order,
                $material->name,
                (bool) $attributes['unforseen'],
                $task,
            ));
        }
    }

    public function createFreeform(Model $owner, array $attributes): FreeformMaterial
    {
        $freeform = $owner->freeformMaterials()->create($attributes);

        $this->announce($owner, fn ($order, $task) => new FreeformMaterialAdded(
            $order,
            $attributes['description'],
            (float) $attributes['quantity'],
            $task,
        ));

        return $freeform;
    }

    public function updateFreeform(Model $owner, FreeformMaterial $freeform, array $attributes): void
    {
        $quantity_changed = array_key_exists('quantity', $attributes)
            && (float) $attributes['quantity'] !== (float) $freeform->quantity;

        if ($quantity_changed) {
            $this->announce($owner, fn ($order, $task) => new FreeformMaterialQuantityChanged(
                $order,
                $freeform->description,
                (float) $freeform->quantity,
                (float) $attributes['quantity'],
                $task,
            ));
        }

        if (array_key_exists('unforseen', $attributes) && $attributes['unforseen'] !== $freeform->unforseen) {
            $this->announce($owner, fn ($order, $task) => new FreeformMaterialUnforeseenFlagChanged(
                $order,
                $freeform->description,
                (bool) $attributes['unforseen'],
                $task,
            ));
        }

        $freeform->update($attributes);
    }

    public function deleteFreeform(Model $owner, FreeformMaterial $freeform): void
    {
        $this->announce($owner, fn ($order, $task) => new FreeformMaterialRemoved(
            $order,
            $freeform->description,
            (float) $freeform->quantity,
            $task,
        ));

        $freeform->delete();
    }

    /**
     * Hands the owner's materials back to stock and drops its rows from both material
     * tables. Called from the deleting hooks: nothing below a service order cascades
     * through Eloquent, so the rows would otherwise outlive every owner that could
     * account for them.
     */
    public function release(Model $owner, string $reason): void
    {
        $owner->loadMissing('materials');

        foreach ($owner->materials as $material) {
            $quantity = (float) ($material->pivot->quantity ?? 0);

            if ($quantity > 0) {
                $stock_before = (float) $material->stock;
                $material->increment('stock', $quantity);

                Signals::dispatch(new MaterialStockRestored(
                    $material,
                    (float) $quantity,
                    $reason,
                    $stock_before,
                    (float) $material->stock,
                ));
            }
        }

        DB::table('materiables')
            ->where('materiable_type', $owner->getMorphClass())
            ->where('materiable_id', $owner->getKey())
            ->delete();

        DB::table('freeform_materials')
            ->where('freeformmateriable_type', $owner->getMorphClass())
            ->where('freeformmateriable_id', $owner->getKey())
            ->delete();
    }

    public function serviceOrderFor(Model $owner): ?ServiceOrder
    {
        if ($owner instanceof ServiceOrder) {
            return $owner;
        }

        if ($owner instanceof ServiceOrderTaskInstance) {
            return $owner->serviceOrder;
        }

        return null;
    }

    /**
     * Materials booked against a task instance still belong to that task's werkbon,
     * so every fact lands on the order with the task named alongside it.
     */
    private function announce(Model $owner, callable $build): void
    {
        $service_order = $this->serviceOrderFor($owner);

        if (!$service_order) {
            return;
        }

        event($build($service_order, $this->taskTitle($owner)));
    }

    private function taskTitle(Model $owner): ?string
    {
        if (!$owner instanceof ServiceOrderTaskInstance) {
            return null;
        }

        return $owner->effective_title ?: 'zonder titel';
    }
}
