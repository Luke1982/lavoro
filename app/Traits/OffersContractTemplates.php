<?php

namespace App\Traits;

use App\Models\MaintenanceContractTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The templates as whoever asks may see them: the template list, and the
 * sjabloon picker of the "nieuw onderhoudscontract" drawer, which is offered
 * from more than one page. Someone without the right to see templates gets
 * none, so the picker isn't there at all; the prices come along only for
 * whoever may see them, which includes everyone who may create a contract.
 *
 * A template with a price formula says which, so the drawer can show that the
 * price will follow that formula instead of asking for one.
 */
trait OffersContractTemplates
{
    protected function templatesFor(Request $request): Collection
    {
        if (!$request->user()->can('viewAny', MaintenanceContractTemplate::class)) {
            return collect();
        }

        $templates = MaintenanceContractTemplate::with('priceFormula:id,name')->orderBy('name')->get();
        $sees_financials = $request->user()->can('seeFinancials', MaintenanceContractTemplate::class);

        $templates->each(fn (MaintenanceContractTemplate $template) => $template->setAttribute(
            'has_price_formula',
            $template->price_formula_id !== null
        ));

        return $sees_financials
            ? $templates->makeVisible(MaintenanceContractTemplate::FINANCIAL_ATTRIBUTES)
            : $templates;
    }
}
