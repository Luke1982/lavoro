<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\BookInvoiceRequest;
use App\Http\Requests\Landlord\CreateEBoekhoudenRelationRequest;
use App\Http\Requests\Landlord\LinkEBoekhoudenRelationRequest;
use App\Http\Requests\Landlord\SearchEBoekhoudenRelationsRequest;
use App\Http\Requests\Landlord\UnlinkEBoekhoudenRelationRequest;
use App\Models\Central\Invoice;
use App\Models\Tenant;
use App\Services\EBoekhouden\InvoiceBooker;
use App\Services\EBoekhouden\RelationLinker;

/**
 * Our own bookkeeping: which relation a customer is in e-Boekhouden, and
 * booking their invoices there.
 */
class EBoekhoudenController extends Controller
{
    /**
     * The matches come back to the subscription screen as flashed data, so the
     * screen shows them after the redirect without an address of its own.
     */
    public function searchRelations(SearchEBoekhoudenRelationsRequest $request, RelationLinker $linker, string $id)
    {
        Tenant::on('central')->findOrFail($id);
        $term = $request->validated()['term'];

        return back()
            ->with('eboekhouden_term', $term)
            ->with('eboekhouden_matches', $linker->search($term));
    }

    public function linkRelation(LinkEBoekhoudenRelationRequest $request, RelationLinker $linker, string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);
        $relation = $linker->link($tenant, $request->validated()['code']);

        return back()->with('status', "{$tenant->name} is gekoppeld aan relatie {$relation['code']} ({$relation['name']}).");
    }

    public function unlinkRelation(UnlinkEBoekhoudenRelationRequest $request, RelationLinker $linker, string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);
        $linker->unlink($tenant);

        return back()->with('status', "{$tenant->name} is niet meer gekoppeld aan e-Boekhouden.");
    }

    public function createRelation(CreateEBoekhoudenRelationRequest $request, RelationLinker $linker, string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);
        $relation = $linker->create($tenant);

        return back()->with('status', "Relatie {$relation['code']} aangemaakt in e-Boekhouden en gekoppeld.");
    }

    public function bookInvoice(BookInvoiceRequest $request, InvoiceBooker $booker, string $id, int $invoice_id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);
        $invoice = Invoice::on('central')->where('tenant_id', $tenant->id)->findOrFail($invoice_id);

        $booker->book($invoice, $tenant);

        return back()->with('status', $invoice->booking_number
            ? "{$invoice->number} is geboekt in e-Boekhouden (mutatie {$invoice->booking_number})."
            : "{$invoice->number} stond al in e-Boekhouden; nu ook hier als geboekt gemarkeerd.");
    }
}
