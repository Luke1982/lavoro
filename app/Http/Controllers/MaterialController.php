<?php

namespace App\Http\Controllers;

use App\Actions\Materials\CreateMaterialAction;
use App\Domain\Integrations\Area;
use App\Exceptions\Refusal;
use App\Http\Requests\MaterialDestroyRequest;
use App\Http\Requests\MaterialReadRequest;
use App\Http\Requests\MaterialStoreRequest;
use App\Http\Requests\MaterialUpdateRequest;
use App\Models\AssistantFilter;
use App\Models\IntegrationConnection;
use App\Models\LedgerAccount;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\MaterialUsageUnit;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

class MaterialController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(MaterialReadRequest $request)
    {
        $search = trim((string) $request->input('search', ''));

        $query = Material::with(['category', 'usageUnit']);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhereHas('category', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('usageUnit', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        return inertia('Materials/IndexPage', [
            'materials' => $query->tap(AssistantFilter::from($request))
                ->orderBy('name')->paginate(20)->appends(['search' => $search])->withQueryString(),
            'categories' => MaterialCategory::orderBy('name')->get(),
            'usageUnits' => MaterialUsageUnit::orderBy('name')->get(),
            'search' => $search,
            'pullSources' => IntegrationConnection::pullButtons(Area::Materials, $request->user()),
            'canImport' => $request->user()->can('import', Material::class),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(MaterialStoreRequest $request, CreateMaterialAction $create_material)
    {
        $material = $create_material->execute($request->validated());

        if ($request->wantsJson()) {
            return response()->json($material->load(['category', 'usageUnit']));
        }

        return redirect()->back()
            ->with('success', 'Materiaal aangemaakt.')
            ->with('extra', $material->load(['category', 'usageUnit']));
    }

    /**
     * Display the specified resource.
     */
    public function show(MaterialReadRequest $request, Material $material)
    {
        $material->load([
            'category',
            'usageUnit',
            'suppliers',
            'activities' => fn ($query) => $query->visibleTo($request->user())
                ->with(['user:id,name', 'fieldChanges'])
                ->orderByDesc('activityables.created_at'),
        ]);

        if ($request->user()->can('seeFinancials', Material::class)) {
            $material->makeVisible(Material::FINANCIAL_ATTRIBUTES);
        }

        $supplier_count = Supplier::count();
        $all_suppliers = $supplier_count <= 50
            ? Supplier::orderBy('name')->get(['id', 'name'])
            : collect();

        return inertia('Materials/ShowPage', [
            'material' => $material,
            'categories' => MaterialCategory::orderBy('name')->get(['id', 'name']),
            'usageUnits' => MaterialUsageUnit::orderBy('name')->get(['id', 'name']),
            'ledgerAccounts' => LedgerAccount::choices(),
            'materialSuppliers' => $material->suppliers->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'article_number' => $s->pivot->article_number,
                'is_preferred' => (bool) $s->pivot->is_preferred,
            ])->values()->all(),
            'allSuppliers' => $all_suppliers,
            'suppliersUseAjax' => $supplier_count > 50,
            'activities' => $material->activities,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(MaterialUpdateRequest $request, Material $material)
    {
        $material->update($request->validated());

        return redirect()->back()->with('success', 'Materiaal is bijgewerkt.');
    }

    /**
     * A material booked on a werkbon cannot go: the booking points at it, and the
     * werkbon would lose what was used. In one transaction, because its supplier
     * links and its place among products' standard materials are taken off one by
     * one before it goes.
     */
    public function destroy(MaterialDestroyRequest $request, Material $material)
    {
        $booked = DB::table('materiables')->where('material_id', $material->id)->count();

        if ($booked > 0) {
            throw new Refusal(
                'Materiaal ' . $material->name . ' kan niet verwijderd worden: het is nog '
                    . ($booked === 1 ? '1 keer' : $booked . ' keer') . ' geboekt op werkbonnen.'
                    . ' Zet het materiaal op inactief als het niet meer gebruikt wordt.'
            );
        }

        DB::transaction(fn () => $material->delete());

        return redirect()->back()->with('success', 'Materiaal verwijderd.');
    }
}
