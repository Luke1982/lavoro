<?php

namespace App\Http\Controllers;

use App\Domain\Integrations\Area;
use App\Exceptions\Refusal;
use App\Http\Requests\Integrations\LedgerAccountManageRequest;
use App\Http\Requests\Integrations\LedgerAccountStoreRequest;
use App\Http\Requests\Integrations\LedgerAccountUpdateRequest;
use App\Models\Integrationable;
use App\Models\IntegrationConnection;
use App\Models\LedgerAccount;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * Koppelingen → Grootboekrekeningen: the revenue accounts materials and
 * products are booked on. Fetching and sending them is the connection's work,
 * like for customers; this screen only keeps the list.
 */
class LedgerAccountController extends Controller
{
    public function index(LedgerAccountManageRequest $request): Response
    {
        $accounts = LedgerAccount::withCount(['materials', 'products'])
            ->with('integrationLinks.integrationConnection')
            ->orderBy('code')
            ->orderBy('name')
            ->get();

        return inertia('Admin/LedgerAccounts/IndexPage', [
            'ledgerAccounts' => $accounts->map(fn (LedgerAccount $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'label' => $account->label,
                'materials_count' => $account->materials_count,
                'products_count' => $account->products_count,
                'linked' => $account->integrationLinks
                    ->filter(fn (Integrationable $link) => !$link->missing_at && $link->integrationConnection)
                    ->map(fn (Integrationable $link) => $link->integrationConnection->label())
                    ->values(),
            ]),
            'inUse' => LedgerAccount::inUse(),
            'pullSources' => IntegrationConnection::pullButtons(Area::LedgerAccounts, $request->user()),
            'sendsTo' => IntegrationConnection::writing(Area::LedgerAccounts)
                ->map(fn (IntegrationConnection $connection) => $connection->label())
                ->values(),
        ]);
    }

    public function store(LedgerAccountStoreRequest $request): RedirectResponse
    {
        $account = LedgerAccount::create($request->validated());

        return back()->with('success', 'Grootboekrekening ' . $account->label . ' toegevoegd.');
    }

    public function update(LedgerAccountUpdateRequest $request, LedgerAccount $ledger_account): RedirectResponse
    {
        $ledger_account->update($request->validated());

        return back()->with('success', 'Grootboekrekening ' . $ledger_account->label . ' opgeslagen.');
    }

    /** Never while something is booked on it: that choice would go with it, unseen. */
    public function destroy(LedgerAccountManageRequest $request, LedgerAccount $ledger_account): RedirectResponse
    {
        $materials = $ledger_account->materials()->count();
        $products = $ledger_account->products()->count();

        if ($materials + $products > 0) {
            $used = array_filter([
                $materials ? $materials . ($materials === 1 ? ' materiaal' : ' materialen') : null,
                $products ? $products . ($products === 1 ? ' product' : ' producten') : null,
            ]);

            throw new Refusal('Grootboekrekening ' . $ledger_account->label . ' hoort nog bij ' . implode(' en ', $used)
                . '. Kies daar eerst een andere.');
        }

        $ledger_account->delete();

        return back()->with('success', 'Grootboekrekening ' . $ledger_account->label . ' verwijderd.');
    }
}
