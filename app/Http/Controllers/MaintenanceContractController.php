<?php

namespace App\Http\Controllers;

use App\Actions\Contracts\AttachContractAssetAction;
use App\Actions\Contracts\CreateMaintenanceContractAction;
use App\Actions\Contracts\DetachContractAssetAction;
use App\Actions\Contracts\UpdateMaintenanceContractAction;
use App\Domain\Pricing\ContractPricing;
use App\Domain\Signals\Contracts\ContractAssetFrequencyChanged;
use App\Domain\Signals\Signals;
use App\Enums\ContractInterval;
use App\Http\Requests\MaintenanceContractAttachAssetRequest;
use App\Http\Requests\MaintenanceContractDestroyRequest;
use App\Http\Requests\MaintenanceContractDetachAssetRequest;
use App\Http\Requests\MaintenanceContractGenerateServiceOrdersRequest;
use App\Http\Requests\MaintenanceContractReadRequest;
use App\Http\Requests\MaintenanceContractStoreRequest;
use App\Http\Requests\MaintenanceContractUpdateAssetableRequest;
use App\Http\Requests\MaintenanceContractUpdateRequest;
use App\Models\Asset;
use App\Models\AssistantFilter;
use App\Models\Customer;
use App\Models\DocumentCategory;
use App\Models\MaintenanceContract;
use App\Models\PriceFormula;
use App\Models\ServiceOrderStage;
use App\Services\MaintenanceContractServiceOrderGenerator;
use App\Support\Money;
use App\Traits\OffersContractTemplates;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MaintenanceContractController extends Controller
{
    use OffersContractTemplates;

    public function index(MaintenanceContractReadRequest $request)
    {
        $search = trim((string) $request->input('search', ''));

        $query = MaintenanceContract::query()->with('customer');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        if ($search !== '') {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")));
        }

        if ($request->filled('onlyStatus')) {
            $today = now()->toDateString();
            match ($request->input('onlyStatus')) {
                'geannuleerd' => $query->whereNotNull('cancelled_at'),
                'toekomstig' => $query->whereNull('cancelled_at')->where('start_date', '>', $today),
                'verlopen' => $query->whereNull('cancelled_at')
                    ->where('start_date', '<=', $today)
                    ->whereNotNull('end_date')
                    ->where('end_date', '<', $today),
                'actief' => $query->whereNull('cancelled_at')
                    ->where('start_date', '<=', $today)
                    ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today)),
                default => null,
            };
        }

        $maintenancecontracts = $query->tap(AssistantFilter::from($request))
            ->orderByDesc('start_date')->paginate(20)->withQueryString();

        if ($request->user()->can('seeFinancials', MaintenanceContract::class)) {
            $maintenancecontracts->getCollection()->makeVisible(MaintenanceContract::FINANCIAL_ATTRIBUTES);
        }

        /**
         * Past the threshold the comboboxes on this page search server-side instead
         * of holding every customer, which had the browser rendering thousands of
         * options on every keystroke. Only the customer being filtered on still
         * travels along, or the filter would show a blank where its name goes.
         */
        $customers_use_ajax = Customer::count() > Customer::COMBO_AJAX_THRESHOLD;
        $customer_query = Customer::query()->orderBy('name');

        if ($customers_use_ajax) {
            $customer_query->whereKey($request->input('customer_id'));
        }

        return inertia('MaintenanceContracts/IndexPage', [
            'maintenanceContracts' => $maintenancecontracts,
            'allCustomers' => $customer_query->get(['id', 'name', 'city'])->map->toComboOption(),
            'customersUseAjax' => $customers_use_ajax,
            'contractIntervalOptions' => ContractInterval::comboBoxArray(),
            'contractTemplates' => $this->templatesFor($request),
            'search' => $search,
            'onlyStatus' => $request->input('onlyStatus', ''),
        ]);
    }

    public function show(MaintenanceContractReadRequest $request, MaintenanceContract $maintenancecontract)
    {
        $maintenancecontract->load([
            'customer',
            'assets.product.brand',
            'assets.product.productType',
            'assets.product.images',
            'assets.linkedLocation',
            'activities' => function ($q) {
                $q->visibleTo(Auth::user())
                    ->with(['user:id,name', 'fieldChanges'])
                    ->orderByDesc('activityables.created_at');
            },
            'remarks.user',
            'documents.category',
            'documents.user:id,name',
            'generatedServiceOrders.serviceJobs.asset.product.brand',
        ]);

        $sees_financials = $request->user()->can('seeFinancials', MaintenanceContract::class);

        if ($sees_financials) {
            $maintenancecontract->makeVisible(MaintenanceContract::FINANCIAL_ATTRIBUTES);
        }

        /**
         * The machines the customer owns. A part belongs to its machine, not to the
         * customer, and comes under a contract only with that machine: attaching
         * one directly is refused here as it is from the bakje.
         */
        $customer_assets = $maintenancecontract->customer
            ? $maintenancecontract->customer->assets()->with([
                'product.brand',
                'product.productType',
                'product.images',
                'linkedLocation',
                'childAssets.product.brand',
            ])->get()
            : collect();

        return inertia('MaintenanceContracts/ShowPage', [
            'maintenanceContract' => $maintenancecontract,
            'customerAssets' => $customer_assets,
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'contractIntervalOptions' => ContractInterval::comboBoxArray(),
            'documentCategories' => DocumentCategory::forPicker(),
            'firstStageName' => ServiceOrderStage::orderBy('order')->value('name'),
            ...($sees_financials ? $this->priceFormulaProps($maintenancecontract) : []),
        ]);
    }

    /**
     * The formula the contract is priced by, what it comes to, what the formula's
     * newest version would make of it, and every formula to pick from.
     *
     * @return array<string, mixed>
     */
    private function priceFormulaProps(MaintenanceContract $maintenancecontract): array
    {
        $pricing = app(ContractPricing::class);
        $version = $maintenancecontract->priceFormulaVersion()->with('formula.latestVersion')->first();
        $latest = $version?->formula?->latestVersion;
        $inputs = $maintenancecontract->price_formula_inputs ?? [];

        return [
            'priceFormulas' => PriceFormula::orderBy('name')->get(['id', 'name']),
            'priceFormula' => $version === null ? null : [
                'formula_id' => $version->price_formula_id,
                'name' => $version->formula?->name,
                'version' => $version->number,
                'version_name' => $version->name,
                'inputs' => array_map(fn (array $field) => $field + [
                    'value' => $inputs[$field['id']] ?? null,
                ], ContractPricing::inputsOf($version->graph)),
                'result' => Arr::except($pricing->evaluate($maintenancecontract, $version), 'nodes'),
                'newer' => $latest && $latest->number > $version->number ? [
                    'version' => $latest->number,
                    'version_name' => $latest->name,
                    'result' => Arr::except($pricing->evaluate($maintenancecontract, $latest), 'nodes'),
                ] : null,
            ],
        ];
    }

    public function store(MaintenanceContractStoreRequest $request)
    {
        app(CreateMaintenanceContractAction::class)->execute($request->validated());

        return redirect()->back()->with('success', 'Onderhoudscontract aangemaakt.');
    }

    public function update(MaintenanceContractUpdateRequest $request, MaintenanceContract $maintenancecontract)
    {
        app(UpdateMaintenanceContractAction::class)->execute($maintenancecontract, $request->validated());

        return redirect()->back()->with('success', 'Onderhoudscontract bijgewerkt.');
    }

    /**
     * Its werkbonnen and machines are told they left it as it goes, so the whole
     * removal holds or none of it does.
     */
    public function destroy(MaintenanceContractDestroyRequest $request, MaintenanceContract $maintenancecontract)
    {
        DB::transaction(fn () => $maintenancecontract->delete());

        return redirect()->route('maintenancecontracts.index')->with('success', 'Onderhoudscontract verwijderd.');
    }

    public function generateServiceOrders(
        MaintenanceContractGenerateServiceOrdersRequest $request,
        MaintenanceContract $maintenancecontract
    ) {
        $service_orders = app(MaintenanceContractServiceOrderGenerator::class)
            ->generateNowForContract($maintenancecontract);
        $count = count($service_orders);

        if ($count === 0) {
            $message = 'Voeg eerst machines toe aan het contract voordat je een werkbon aanmaakt.';
        } elseif ($count === 1) {
            $message = "Werkbon #{$service_orders[0]->id} aangemaakt.";
        } else {
            $message = "{$count} werkbonnen aangemaakt (één per locatie).";
        }

        return redirect()->back()->with('success', $message);
    }

    public function attachAsset(
        MaintenanceContractAttachAssetRequest $request,
        MaintenanceContract $maintenancecontract,
        Asset $asset
    ) {
        $validated = $request->validated();

        $price_before = $maintenancecontract->price;

        app(AttachContractAssetAction::class)->execute(
            $maintenancecontract,
            $asset,
            $validated['frequency'] ?? null,
            $validated['frequency_days'] ?? null,
        );

        return redirect()->back()->with(
            'success',
            'Machine gekoppeld aan het contract.' . $this->priceFeedback($maintenancecontract, $price_before)
        );
    }

    public function updateAssetable(
        MaintenanceContractUpdateAssetableRequest $request,
        MaintenanceContract $maintenancecontract,
        string $assetable_id
    ) {
        $pivot_query = $maintenancecontract->assets()->newPivotQuery()->where('assetables.id', $assetable_id);
        $record = $pivot_query->first();
        $asset = $record ? Asset::find($record->asset_id) : null;

        $pivot_query->update($request->validated());

        $updated = $maintenancecontract->assets()->newPivotQuery()->where('assetables.id', $assetable_id)->first();
        $changed = $updated !== null
            && [$record->frequency, $record->frequency_days] !== [$updated->frequency, $updated->frequency_days];

        if ($asset && $changed) {
            Signals::dispatch(new ContractAssetFrequencyChanged(
                $maintenancecontract,
                $asset,
                $record->frequency,
                $record->frequency_days === null ? null : (int) $record->frequency_days,
                $updated->frequency,
                $updated->frequency_days === null ? null : (int) $updated->frequency_days,
            ));
        }

        return redirect()->back()->with('success', 'Frequentie bijgewerkt.');
    }

    public function detachAsset(
        MaintenanceContractDetachAssetRequest $request,
        MaintenanceContract $maintenancecontract,
        string $assetable_id
    ) {
        $price_before = $maintenancecontract->price;

        app(DetachContractAssetAction::class)->execute($maintenancecontract, (int) $assetable_id);

        return redirect()->back()->with(
            'success',
            'Machine losgekoppeld van het contract.' . $this->priceFeedback($maintenancecontract, $price_before)
        );
    }

    /**
     * A contract with a price formula reprices itself when a machine comes or
     * goes; whoever did it hears what that did to the price, if they may see it.
     */
    private function priceFeedback(MaintenanceContract $maintenancecontract, mixed $price_before): string
    {
        if (
            $maintenancecontract->price_formula_version_id === null
            || !request()->user()->can('seeFinancials', MaintenanceContract::class)
        ) {
            return '';
        }

        $price_after = $maintenancecontract->fresh()->price;

        if ((string) $price_after === (string) $price_before) {
            return ' De prijs blijft ' . Money::euros($price_after) . '.';
        }

        return ' De prijs gaat van ' . Money::euros($price_before) . ' naar ' . Money::euros($price_after) . '.';
    }
}
