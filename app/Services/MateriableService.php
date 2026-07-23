<?php

namespace App\Services;

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

        $this->logOnServiceOrder(
            $owner,
            sprintf('Materiaal toegevoegd: %s (aantal %s)', $material->name, $attributes['quantity']),
            [$material]
        );
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
        $this->logOnServiceOrder(
            $owner,
            sprintf('Materiaal verwijderd: %s', $material->name),
            [$material]
        );
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

            $this->logOnServiceOrder(
                $owner,
                sprintf('Materiaal hoeveelheid aangepast: %s naar %s', $material->name, $attributes['quantity']),
                [$material]
            );
        }

        if (array_key_exists('unforseen', $attributes)) {
            $this->logOnServiceOrder($owner, sprintf(
                'Materiaal gemarkeerd als %s: %s',
                $attributes['unforseen'] ? 'onvoorzien' : 'voorzien',
                $material->name
            ));
        }
    }

    public function createFreeform(Model $owner, array $attributes): FreeformMaterial
    {
        $freeform = $owner->freeformMaterials()->create($attributes);

        $this->logOnServiceOrder($owner, sprintf(
            'Vrije materiaalregel toegevoegd: %s (aantal %s)',
            $attributes['description'],
            $attributes['quantity']
        ));

        return $freeform;
    }

    public function updateFreeform(Model $owner, FreeformMaterial $freeform, array $attributes): void
    {
        $quantity_changed = array_key_exists('quantity', $attributes)
            && (float) $attributes['quantity'] !== (float) $freeform->quantity;

        if ($quantity_changed) {
            $this->logOnServiceOrder($owner, sprintf(
                'Vrije materiaalregel hoeveelheid aangepast: %s naar %s',
                $freeform->description,
                $attributes['quantity']
            ));
        }

        if (array_key_exists('unforseen', $attributes) && $attributes['unforseen'] !== $freeform->unforseen) {
            $this->logOnServiceOrder($owner, sprintf(
                'Vrije materiaalregel gemarkeerd als %s: %s',
                $attributes['unforseen'] ? 'onvoorzien' : 'voorzien',
                $freeform->description
            ));
        }

        $freeform->update($attributes);
    }

    public function deleteFreeform(Model $owner, FreeformMaterial $freeform): void
    {
        $this->logOnServiceOrder($owner, sprintf(
            'Vrije materiaalregel verwijderd: %s (aantal %s)',
            $freeform->description,
            $freeform->quantity
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
                $material->increment('stock', $quantity);
                $material->logActivity('Voorraad hersteld: +' . $quantity . ' door ' . $reason);
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

    private function logOnServiceOrder(Model $owner, string $message, array $also_attach_to = []): void
    {
        $service_order = $this->serviceOrderFor($owner);

        if (!$service_order) {
            return;
        }

        $service_order->logActivity(
            $message . $this->contextSuffix($owner),
            also_attach_to: $also_attach_to,
            metadata: [
                'service_order_id' => $service_order->id,
                'service_order_number' => $service_order->id,
            ]
        );
    }

    private function contextSuffix(Model $owner): string
    {
        if (!$owner instanceof ServiceOrderTaskInstance) {
            return '';
        }

        return ' (taak: ' . ($owner->effective_title ?: 'zonder titel') . ')';
    }
}
