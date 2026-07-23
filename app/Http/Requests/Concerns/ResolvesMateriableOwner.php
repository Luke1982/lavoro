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
}
