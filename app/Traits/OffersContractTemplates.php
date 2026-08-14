<?php

namespace App\Traits;

use App\Models\MaintenanceContractTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Feeds the sjabloon picker of the "nieuw onderhoudscontract" drawer, which is
 * offered from more than one page. Someone without the right to see templates
 * gets none, so the picker isn't there at all.
 */
trait OffersContractTemplates
{
    protected function templatesFor(Request $request): Collection
    {
        return $request->user()->can('viewAny', MaintenanceContractTemplate::class)
            ? MaintenanceContractTemplate::orderBy('name')->get()
            : collect();
    }
}
