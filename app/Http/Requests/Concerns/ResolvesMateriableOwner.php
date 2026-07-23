<?php

namespace App\Http\Requests\Concerns;

use App\Services\MateriableService;
use Illuminate\Database\Eloquent\Model;

/**
 * Materials hang off a service order or off one of its task instances, so these requests
 * are bound under either route. Authorization always resolves back to the service order:
 * booking a material against a task is the same act as booking it against the order.
 */
trait ResolvesMateriableOwner
{
    protected function materiableOwner(): ?Model
    {
        return $this->route('serviceorder') ?? $this->route('serviceordertaskinstance');
    }

    protected function authorizeMateriable(string $ability): bool
    {
        $service_order = app(MateriableService::class)->serviceOrderFor($this->materiableOwner());

        return $service_order !== null && $this->user()->can($ability, $service_order);
    }

    /**
     * Whether a morph type/id pair points back at the owner in the route, so a line can
     * only be touched through the URL of the owner it actually belongs to.
     */
    protected function ownerMatches(?string $morph_type, int|string|null $morph_id): bool
    {
        $owner = $this->materiableOwner();

        return $owner !== null
            && $morph_type === $owner->getMorphClass()
            && (int) $morph_id === (int) $owner->getKey();
    }
}
