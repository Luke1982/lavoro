<?php

namespace App\Services;

use App\Domain\Signals\Materials\MaterialStockRestored;
use App\Domain\Signals\ServiceOrders\FreeformMaterialAdded;
use App\Domain\Signals\ServiceOrders\FreeformMaterialQuantityChanged;
use App\Domain\Signals\ServiceOrders\FreeformMaterialRemoved;
use App\Domain\Signals\ServiceOrders\FreeformMaterialRenamed;
use App\Domain\Signals\ServiceOrders\FreeformMaterialUnforeseenFlagChanged;
use App\Domain\Signals\ServiceOrders\MaterialAttachedToOrder;
use App\Domain\Signals\ServiceOrders\MaterialDetachedFromOrder;
use App\Domain\Signals\ServiceOrders\MaterialQuantityChanged;
use App\Domain\Signals\ServiceOrders\MaterialUnforeseenFlagChanged;
use App\Domain\Signals\Signals;
use App\Exceptions\Refusal;
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
 *
 * Once the owner is done (lockedBecause()) every write here is refused, so a request sent
 * by hand or the assistant cannot move stock on a list the screen shows read-only.
 * release() is not a write on a living list and is never refused.
 */
class MateriableService
{
    public function attach(Model $owner, Material $material, array $attributes): void
    {
        $this->refuseWhenLocked($owner);

        $owner->materials()->attach($material, [
            'quantity' => $attributes['quantity'],
            'unforseen' => $attributes['unforseen'] ?? false,
        ]);
        [$stock_before, $stock_after] = $this->moveStock($material, -(float) $attributes['quantity']);

        $this->announce($owner, fn ($order, $task) => new MaterialAttachedToOrder(
            $order,
            $material->name,
            (float) $attributes['quantity'],
            $task,
            [$material],
            $stock_before,
            $stock_after,
        ));
    }

    public function detach(Model $owner, string $materiable_id): void
    {
        $this->refuseWhenLocked($owner);

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

        [$stock_before, $stock_after] = $this->moveStock($material, $quantity);

        $this->announce($owner, fn ($order, $task) => new MaterialDetachedFromOrder(
            $order,
            $material->name,
            $quantity,
            $task,
            [$material],
            $stock_before,
            $stock_after,
        ));
    }

    public function update(Model $owner, string $materiable_id, array $attributes): void
    {
        $this->refuseWhenLocked($owner);

        $pivot_query = $owner->materials()
            ->newPivotQuery()
            ->where('materiables.id', $materiable_id);

        $record = $pivot_query->first();
        $material = $record ? Material::find($record->material_id) : null;

        $pivot_query->update($attributes);

        if (!$material) {
            return;
        }

        $old_quantity = $record->quantity !== null ? (float) $record->quantity : null;
        $new_quantity = array_key_exists('quantity', $attributes) ? (float) $attributes['quantity'] : $old_quantity;

        if ($new_quantity !== $old_quantity) {
            [$stock_before, $stock_after] = $old_quantity === null
                ? [null, null]
                : $this->moveStock($material, $old_quantity - $new_quantity);

            $this->announce($owner, fn ($order, $task) => new MaterialQuantityChanged(
                $order,
                $material->name,
                $old_quantity,
                $new_quantity,
                $task,
                [$material],
                $stock_before,
                $stock_after,
            ));
        }

        if (
            array_key_exists('unforseen', $attributes)
            && (bool) $attributes['unforseen'] !== (bool) $record->unforseen
        ) {
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
        $this->refuseWhenLocked($owner);

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
        $this->refuseWhenLocked($owner);

        $previous_description = (string) $freeform->description;
        $previous_quantity = (float) $freeform->quantity;
        $previous_unforeseen = (bool) $freeform->unforseen;

        $freeform->update($attributes);

        $description = (string) $freeform->description;

        if ($description !== $previous_description) {
            $this->announce($owner, fn ($order, $task) => new FreeformMaterialRenamed(
                $order,
                $previous_description,
                $description,
                $task,
            ));
        }

        if ((float) $freeform->quantity !== $previous_quantity) {
            $this->announce($owner, fn ($order, $task) => new FreeformMaterialQuantityChanged(
                $order,
                $description,
                $previous_quantity,
                (float) $freeform->quantity,
                $task,
            ));
        }

        if ((bool) $freeform->unforseen !== $previous_unforeseen) {
            $this->announce($owner, fn ($order, $task) => new FreeformMaterialUnforeseenFlagChanged(
                $order,
                $description,
                (bool) $freeform->unforseen,
                $task,
            ));
        }
    }

    public function deleteFreeform(Model $owner, FreeformMaterial $freeform): void
    {
        $this->refuseWhenLocked($owner);

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
     *
     * Deleting a single task tells every line it takes with it ($announce_lines), on
     * the werkbon that stays. Deleting a werkbon does not, because its own timeline
     * goes with it and its "Verwijderd" entry on the customer is the news; each
     * material still hears of its stock coming back.
     */
    public function release(Model $owner, string $reason, bool $announce_lines = false): void
    {
        $owner->loadMissing($announce_lines ? ['materials', 'freeformMaterials'] : ['materials']);

        foreach ($owner->materials as $material) {
            $quantity = (float) ($material->pivot->quantity ?? 0);

            if ($quantity > 0) {
                $this->restoreStock($material, $quantity, $reason);
            }

            if ($announce_lines) {
                $this->announce($owner, fn ($order, $task) => new MaterialDetachedFromOrder(
                    $order,
                    $material->name,
                    $quantity,
                    $task,
                    [$material],
                ));
            }
        }

        if ($announce_lines) {
            foreach ($owner->freeformMaterials as $freeform) {
                $this->announce($owner, fn ($order, $task) => new FreeformMaterialRemoved(
                    $order,
                    (string) $freeform->description,
                    (float) $freeform->quantity,
                    $task,
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

    /**
     * Why the materials on this owner can no longer change, or null while they can. A
     * werkbon that is closed or went to the administration is done; a cancelled task
     * is done even while its werkbon is not.
     */
    public function lockedBecause(Model $owner): ?string
    {
        $service_order = $this->serviceOrderFor($owner);

        if ($service_order?->sent_to_administration) {
            return 'Werkbon #' . $service_order->id
                . ' is naar de administratie verzonden; het materiaal erop kan niet meer worden gewijzigd.';
        }

        if ($service_order?->is_closed) {
            return 'Werkbon #' . $service_order->id
                . ' is gesloten. Heropen hem eerst als je het materiaal nog wilt wijzigen.';
        }

        if ($owner instanceof ServiceOrderTaskInstance && $owner->is_cancelled) {
            return 'Taak "' . $owner->timelineTitle() . '" is geannuleerd; het materiaal erop kan niet meer worden gewijzigd.';
        }

        return null;
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

        Signals::dispatch($build($service_order, $this->taskTitle($owner)));
    }

    private function refuseWhenLocked(Model $owner): void
    {
        $locked_because = $this->lockedBecause($owner);

        if ($locked_because !== null) {
            throw new Refusal($locked_because);
        }
    }

    private function restoreStock(Material $material, float $quantity, string $reason): void
    {
        [$stock_before, $stock_after] = $this->moveStock($material, $quantity);

        Signals::dispatch(new MaterialStockRestored($material, $quantity, $reason, $stock_before, $stock_after));
    }

    /**
     * Books stock in or out, and hands back the stock before and after. Through the
     * query builder, so the stock column raises no generic "Voorraad" entry: the named
     * signal of every caller already tells the movement, with these two values. The
     * material in hand is told the new stock, so nobody reads the old one off it.
     *
     * @return array{0: float, 1: float}
     */
    private function moveStock(Material $material, float $change): array
    {
        $stock = Material::query()->whereKey($material->getKey());
        $stock_before = (float) (clone $stock)->value('stock');

        (clone $stock)->increment('stock', $change);

        $stock_after = (float) (clone $stock)->value('stock');

        $material->forceFill(['stock' => $stock_after])->syncOriginalAttribute('stock');

        return [$stock_before, $stock_after];
    }

    private function taskTitle(Model $owner): ?string
    {
        if (!$owner instanceof ServiceOrderTaskInstance) {
            return null;
        }

        return $owner->timelineTitle();
    }
}
