<?php

namespace App\Http\Controllers;

use App\Actions\Pricing\CreatePriceFormulaAction;
use App\Actions\Pricing\SavePriceFormulaGraphAction;
use App\Actions\Pricing\UpgradeContractsToLatestVersionAction;
use App\Domain\Pricing\ContractPricing;
use App\Domain\Pricing\NodeCatalog;
use App\Enums\ContractInterval;
use App\Exceptions\Refusal;
use App\Http\Requests\PriceFormulaDestroyRequest;
use App\Http\Requests\PriceFormulaGraphUpdateRequest;
use App\Http\Requests\PriceFormulaPreviewRequest;
use App\Http\Requests\PriceFormulaReadRequest;
use App\Http\Requests\PriceFormulaStoreRequest;
use App\Http\Requests\PriceFormulaUpdateRequest;
use App\Http\Requests\PriceFormulaUpgradeContractsRequest;
use App\Models\Brand;
use App\Models\MaintenanceContract;
use App\Models\PriceFormula;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductType;
use App\Support\NewMachineForm;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class PriceFormulaController extends Controller
{
    public function index(PriceFormulaReadRequest $request)
    {
        $per_version = fn ($query) => $query
            ->join(
                'price_formula_versions',
                'price_formula_versions.id',
                '=',
                'maintenance_contracts.price_formula_version_id'
            )
            ->selectRaw('price_formula_versions.price_formula_id, price_formula_versions.number, count(*) as total')
            ->groupBy('price_formula_versions.price_formula_id', 'price_formula_versions.number')
            ->get()
            ->groupBy('price_formula_id');
        $contracts_per_version = $per_version(MaintenanceContract::query());
        $running_per_version = $per_version(MaintenanceContract::query()->notEnded());

        $formulas = PriceFormula::with(['latestVersion', 'templates:id,name,price_formula_id'])
            ->orderBy('name')
            ->get()
            ->map(function (PriceFormula $formula) use ($contracts_per_version, $running_per_version) {
                $counts = $contracts_per_version->get($formula->id, collect());
                $running = $running_per_version->get($formula->id, collect());
                $latest = $formula->latestVersion?->number;

                return [
                    'id' => $formula->id,
                    'name' => $formula->name,
                    'description' => $formula->description,
                    'version' => $latest,
                    'contracts' => (int) $counts->sum('total'),
                    'outdated' => (int) $running->filter(fn ($row) => (int) $row->number !== $latest)->sum('total'),
                    'templates' => $formula->templates->pluck('name')->values(),
                ];
            });

        return inertia('PriceFormulas/IndexPage', ['formulas' => $formulas]);
    }

    public function store(PriceFormulaStoreRequest $request)
    {
        $formula = app(CreatePriceFormulaAction::class)->execute(
            $request->validated('name'),
            $request->validated('description'),
            null,
            $request->user(),
        );

        return redirect()->route('priceformulas.show', $formula)->with('success', 'Prijsformule aangemaakt.');
    }

    public function show(PriceFormulaReadRequest $request, PriceFormula $priceformula)
    {
        $version = $priceformula->latestVersion()->with('creator:id,name')->first();
        $graph = $version?->graph ?? NodeCatalog::starterGraph();
        $sees_contracts = $request->user()->can('viewAny', MaintenanceContract::class)
            && $request->user()->can('seeFinancials', MaintenanceContract::class);

        return inertia('PriceFormulas/ShowPage', [
            'formula' => $priceformula->only(['id', 'name', 'description']),
            'version' => [
                'number' => $version?->number ?? 1,
                'graph' => $graph,
                'saved_at' => $version?->updated_at,
                'saved_by' => $version?->creator?->name,
                'in_use' => $version?->contracts()->exists() ?? false,
            ],
            'catalog' => NodeCatalog::forEditor(),
            'productTypes' => ProductType::flatListWithPath(),
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
            'attributes' => ProductAttribute::orderBy('name')->get(['id', 'name', 'value_type', 'unit']),
            ...$this->productPicker($graph),
            'intervalOptions' => array_map(fn (array $option) => $option['name'], ContractInterval::comboBoxArray()),
            'templates' => $priceformula->templates()->orderBy('name')->get(['id', 'name']),
            'seesContracts' => $sees_contracts,
            'contracts' => $sees_contracts
                ? Inertia::defer(fn () => $this->contractsOn($priceformula), 'contracts')
                : [],
            'testContracts' => $sees_contracts ? Inertia::defer(fn () => $this->testContracts(), 'contracts') : [],
            'tourTypes' => $this->tourTypes(),
            'testContractId' => $sees_contracts ? $request->integer('contract') ?: null : null,
        ]);
    }

    public function update(PriceFormulaUpdateRequest $request, PriceFormula $priceformula)
    {
        $priceformula->update($request->validated());

        return redirect()->back()->with('success', 'Prijsformule bijgewerkt.');
    }

    /**
     * Says what saving did to the contracts on this formula: nothing yet, when
     * they stay on their version, and how many are now waiting for an update.
     */
    public function updateGraph(PriceFormulaGraphUpdateRequest $request, PriceFormula $priceformula)
    {
        ['version' => $version, 'is_new_version' => $is_new] = app(SavePriceFormulaGraphAction::class)
            ->execute($priceformula, $request->validated('graph'), $request->user());

        $waiting = MaintenanceContract::query()
            ->whereIn('price_formula_version_id', $priceformula->versions()->whereKeyNot($version->id)->select('id'))
            ->notEnded()
            ->count();

        $message = $is_new ? 'Opgeslagen als versie ' . $version->number . '.' : 'Opgeslagen.';

        if ($waiting > 0) {
            $message .= ' ' . ($waiting === 1 ? '1 contract staat' : $waiting . ' contracten staan')
                . ' nog op een oudere versie en ' . ($waiting === 1 ? 'houdt zijn' : 'houden hun')
                . ' prijs tot je ' . ($waiting === 1 ? 'het' : 'ze') . ' bijwerkt.';
        }

        return redirect()->back()->with('success', $message);
    }

    public function destroy(PriceFormulaDestroyRequest $request, PriceFormula $priceformula)
    {
        $in_use = $priceformula->contracts()->count();

        if ($in_use > 0) {
            throw new Refusal(
                'Deze formule kan niet worden verwijderd: ' . ($in_use === 1 ? 'één contract gebruikt' : $in_use . ' contracten gebruiken')
                . ' hem nog. Geef ' . ($in_use === 1 ? 'dat contract' : 'die contracten')
                . ' eerst een andere formule of een vaste prijs.'
            );
        }

        $priceformula->delete();

        return redirect()->route('priceformulas.index')->with('success', 'Prijsformule verwijderd.');
    }

    public function preview(PriceFormulaPreviewRequest $request, ContractPricing $pricing): JsonResponse
    {
        $result = $pricing->trial(
            $request->validated('graph'),
            MaintenanceContract::find($request->validated('maintenance_contract_id')),
            $request->validated('sample') ?? [],
            $request->validated('price_interval'),
            $request->validated('frequency'),
            $request->validated('inputs') ?? [],
        );

        if (isset($result['inputs'])) {
            $result['inputs'] = (object) $result['inputs'];
        }

        return response()->json($result);
    }

    public function upgradeContracts(PriceFormulaUpgradeContractsRequest $request, PriceFormula $priceformula)
    {
        $count = app(UpgradeContractsToLatestVersionAction::class)
            ->execute($priceformula, $request->validated('contract_ids'));

        $message = match ($count) {
            0 => 'Er staat geen lopend contract meer op een oudere versie.',
            1 => 'Eén contract bijgewerkt naar de nieuwste versie. De nieuwe prijs staat op de tijdlijn van het contract.',
            default => $count . ' contracten bijgewerkt naar de nieuwste versie. De nieuwe prijs staat op de tijdlijn van elk contract.',
        };

        return redirect()->back()->with('success', $message);
    }

    /**
     * The contracts on this formula, and for each one still running on an older
     * version what the newest version would make its price. Worked out per
     * contract, so it comes after the page rather than holding it up.
     *
     * @return array<int, array<string, mixed>>
     */
    private function contractsOn(PriceFormula $formula): array
    {
        $latest = $formula->latestVersion;
        $pricing = app(ContractPricing::class);

        return $formula->contracts()
            ->with(['customer:id,name', 'priceFormulaVersion'])
            ->orderByDesc('maintenance_contracts.start_date')
            ->get()
            ->map(function (MaintenanceContract $contract) use ($latest, $pricing) {
                $outdated = $latest && $contract->priceFormulaVersion->number < $latest->number;
                $new = $outdated && !$contract->hasEnded() ? $pricing->evaluate($contract, $latest) : null;

                return [
                    'id' => $contract->id,
                    'name' => $contract->display_title,
                    'ended' => $contract->hasEnded(),
                    'version' => $contract->priceFormulaVersion->number,
                    'price' => (float) $contract->getRawOriginal('price'),
                    'one_time_price' => $contract->getRawOriginal('one_time_price') === null
                        ? null
                        : (float) $contract->getRawOriginal('one_time_price'),
                    'new_price' => $new ? ($new['periodic'] ?? 0.0) : null,
                    'new_one_time_price' => $new ? $new['one_time'] : null,
                ];
            })
            ->all();
    }

    /**
     * The contracts to try a formula on: the ones still running come first.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    private function testContracts(): Collection
    {
        return MaintenanceContract::with('customer:id,name')
            ->orderByRaw('cancelled_at is not null')
            ->orderByDesc('start_date')
            ->get()
            ->map(fn (MaintenanceContract $contract) => ['id' => $contract->id, 'name' => $contract->display_title]);
    }

    /**
     * Two machine types the rondleiding builds its examples with: the ones with
     * the most products, and never one inside the other, so a filter on the first
     * leaves the second for the rest.
     *
     * @return array<int, array{id: int, name: string, short: string}>
     */
    private function tourTypes(): array
    {
        $paths = ProductType::pathMap();
        $chosen = [];

        foreach (ProductType::withCount('products')->orderByDesc('products_count')->orderBy('name')->get() as $type) {
            $path = $paths[$type->id] ?? $type->name;
            $related = collect($chosen)->contains(
                fn (array $other) => str_starts_with($path, $other['name']) || str_starts_with($other['name'], $path)
            );

            if (!$related) {
                $chosen[] = ['id' => $type->id, 'name' => $path, 'short' => $type->name];
            }

            if (count($chosen) === 2) {
                break;
            }
        }

        return $chosen;
    }

    /**
     * The model picker of the settings panel, with the models the graph already
     * names, so they show by name while the picker searches as you type.
     *
     * @return array{productOptions: mixed, productsUseAjax: bool}
     */
    private function productPicker(array $graph): array
    {
        $picker = NewMachineForm::productPickerProps();
        $named = Product::with(['brand', 'productType'])
            ->whereKey(NodeCatalog::productIdsIn($graph))
            ->get()
            ->map->toMachineOption();

        return [
            'productOptions' => collect($picker['allProducts'])->concat($named)->unique('id')->values(),
            'productsUseAjax' => $picker['productsUseAjax'],
        ];
    }
}
