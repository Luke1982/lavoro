<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductType;
use Illuminate\Http\Request;
use App\Http\Requests\ProductStoreUpdateRequest;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $products = Product::query();

        if ($request->has('search')) {
            $products = self::getByTerm($request->search);
        }

        return inertia(
            'Products/IndexPage',
            [
                'products'     => $products
                    ->with(['brand', 'productType'])
                    ->orderBy('model')
                    ->paginate(20),
                'search'       => $request->search,
                'brands'       => Brand::all(),
                'productTypes' => ProductType::all(),
            ]
        );
    }

    /**
     * Build a query filtering by the given search terms.
     */
    private static function getByTerm($term)
    {
        $query = Product::with(['brand', 'productType']);

        $words = preg_split('/\s+/', trim($term));

        foreach ($words as $word) {
            $query->where(function ($q) use ($word) {
                $q->where('model', 'like', '%' . $word . '%')
                  ->orWhereHas('brand', function ($qb) use ($word) {
                      $qb->where('name', 'like', '%' . $word . '%');
                  })
                  ->orWhereHas('productType', function ($qb) use ($word) {
                      $qb->where('name', 'like', '%' . $word . '%');
                  });
            });
        }

        return $query;
    }

    public function show(Product $product)
    {
        return inertia(
            'Products/ShowPage',
            [
                'product' => $product->load(
                    [
                        'brand',
                        'productType',
                        'images',
                        'assets.customer',
                        'assets.openTickets',
                        'assets.pendingTickets',
                        'assets.closedTickets',
                        'assets.product.productType',
                        'assets.product.brand',
                    ]
                ),
            ]
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ProductStoreUpdateRequest $request)
    {
        $product = Product::create([
            'product_type_id' => $request->product_type_id,
            'brand_id'        => $request->brand_id,
            'model'           => $request->model,
            'description'     => $request->description,
            'start_sell'      => $request->start_sell,
            'end_sell'        => $request->end_sell,
            'typical_certificate_days' => $request->typical_certificate_days,
        ]);

        return redirect()
            ->route('products.index')
            ->with('success', 'Product aangemaakt.')
            ->with('extra', $product->load(['brand', 'productType']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ProductStoreUpdateRequest $request, Product $product)
    {
        $product->update([
            'product_type_id' => $request->product_type_id,
            'brand_id'        => $request->brand_id,
            'model'           => $request->model,
            'description'     => $request->description,
            'start_sell'      => $request->start_sell,
            'end_sell'        => $request->end_sell,
            'typical_certificate_days' => $request->typical_certificate_days,
        ]);

        return redirect()
            ->route($request->origin === 'showPage' ? 'products.show' : 'products.index', $product->id)
            ->with('success', 'Product bijgewerkt.')
            ->with('extra', $product->load(['brand', 'productType']));
        ;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Product $product)
    {
        $product->delete();

        return redirect()
            ->route('products.index')
            ->with('success', 'Product verwijderd.');
    }
}
