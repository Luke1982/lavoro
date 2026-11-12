<?php

namespace App\Actions\Locations;

use App\Models\Location;

/**
 * Adds a location to a customer.
 *
 * Thin on purpose, like creating a storing: it exists so the form and the
 * assistant cannot drift. Whatever creating a location comes to mean later, it
 * will mean it in both places.
 *
 * The attributes are whatever LocationStoreRequest's rules let through, put
 * through LocationStoreRequest::normalize(): that request defines the shape.
 */
class CreateLocationAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes): Location
    {
        return Location::create($attributes);
    }
}
