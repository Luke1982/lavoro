<?php

namespace App\Http\Controllers;

use App\Actions\Products\ChangeProductAttributeFormatAction;
use App\Actions\Products\RemoveProductAttributeValueAction;
use App\Enums\ProductAttributeValueTypes;
use App\Http\Requests\ProductAttributeDestroyRequest;
use App\Http\Requests\ProductAttributeReadRequest;
use App\Http\Requests\ProductAttributeStoreRequest;
use App\Http\Requests\ProductAttributeUpdateRequest;
use App\Models\ProductAttribute;
use App\Models\ProductType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ProductAttributeController extends Controller
{
    public function index(ProductAttributeReadRequest $request)
    {
        $search = trim((string) $request->validated('search'));
        $query = ProductAttribute::with(['productTypes']);

        if ($search !== '') {
            $query->where('name', 'like', '%' . $search . '%');
        }

        return inertia('ProductAttributes/IndexPage', [
            'attributes' => $query->orderBy('name')->paginate(20),
            'search' => $search,
            'valueTypes' => ProductAttributeValueTypes::comboArray(),
        ]);
    }

    public function show(ProductAttributeReadRequest $request, ProductAttribute $productattribute)
    {
        $productattribute->load([
            'values',
            'productTypes',
            'activities' => fn ($query) => $query->visibleTo($request->user())
                ->with(['user:id,name', 'fieldChanges'])
                ->orderByDesc('activityables.created_at'),
        ]);

        return inertia('ProductAttributes/ShowPage', [
            'attribute' => $productattribute,
            'productTypes' => ProductType::flatListWithPath(),
            'valueTypes' => ProductAttributeValueTypes::comboArray(),
        ]);
    }

    public function store(ProductAttributeStoreRequest $request, ChangeProductAttributeFormatAction $change_format)
    {
        $data = $request->validated();

        $attribute = DB::transaction(function () use ($data, $change_format) {
            $attribute = ProductAttribute::create(['name' => $data['name']]);
            $change_format->handle($attribute, $data['value_type'], $data['unit'] ?? null);

            return $attribute;
        });

        return redirect()->route('productattributes.show', $attribute)
            ->with('success', 'Kenmerk aangemaakt.');
    }

    public function update(
        ProductAttributeUpdateRequest $request,
        ProductAttribute $productattribute,
        ChangeProductAttributeFormatAction $change_format,
    ) {
        $data = $request->validated();

        DB::transaction(function () use ($data, $productattribute, $change_format) {
            $productattribute->update(Arr::only($data, ['name', 'searchable']));

            if (array_key_exists('value_type', $data) || array_key_exists('unit', $data)) {
                $change_format->handle(
                    $productattribute,
                    $data['value_type'] ?? $productattribute->value_type,
                    array_key_exists('unit', $data) ? $data['unit'] : $productattribute->unit,
                );
            }
        });

        return redirect()->back()->with('success', 'Kenmerk bijgewerkt.');
    }

    /**
     * Each value goes the way a single one does, so every product that had one
     * says on its timeline which value it lost.
     */
    public function destroy(
        ProductAttributeDestroyRequest $request,
        ProductAttribute $productattribute,
        RemoveProductAttributeValueAction $remove_value,
    ) {
        DB::transaction(function () use ($productattribute, $remove_value) {
            $productattribute->values->each($remove_value->handle(...));
            $productattribute->delete();
        });

        return redirect()->route('productattributes.index')
            ->with('success', 'Kenmerk verwijderd.');
    }
}
