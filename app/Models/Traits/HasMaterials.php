<?php

namespace App\Models\Traits;

use App\Models\FreeformMaterial;
use App\Models\Material;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Anything materials can be booked against: a service order, or one of its task instances.
 *
 * Attaching, detaching and releasing move stock and write activity, so those run through
 * MateriableService rather than being called on these relations directly.
 */
trait HasMaterials
{
    public function materials(): MorphToMany
    {
        return $this->morphToMany(Material::class, 'materiable')
            ->withPivot('quantity', 'unforseen', 'id')
            ->withTimestamps();
    }

    public function freeformMaterials(): MorphMany
    {
        return $this->morphMany(FreeformMaterial::class, 'freeformmateriable');
    }
}
