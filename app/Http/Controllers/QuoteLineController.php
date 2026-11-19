<?php

namespace App\Http\Controllers;

use App\Actions\Quotes\AddProductToQuoteAction;
use App\Actions\Quotes\AddQuoteLineAction;
use App\Actions\Quotes\RemoveQuoteLineAction;
use App\Actions\Quotes\UpdateQuoteLineAction;
use App\Enums\QuoteLineKinds;
use App\Http\Requests\QuoteLineDestroyRequest;
use App\Http\Requests\QuoteLineStoreRequest;
use App\Http\Requests\QuoteLineUpdateRequest;
use App\Http\Requests\QuoteProductStoreRequest;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteLine;

class QuoteLineController extends Controller
{
    public function storeProduct(QuoteProductStoreRequest $request, Quote $quote, AddProductToQuoteAction $add)
    {
        $add->execute(
            $quote,
            Product::findOrFail($request->validated('product_id')),
            (float) $request->validated('quantity'),
            $request->validated('materials', []),
        );

        return redirect()->back();
    }

    public function store(QuoteLineStoreRequest $request, Quote $quote, AddQuoteLineAction $add)
    {
        $add->execute($quote, $request->enum('kind', QuoteLineKinds::class), $request->validated());

        return redirect()->back();
    }

    public function update(QuoteLineUpdateRequest $request, Quote $quote, QuoteLine $line, UpdateQuoteLineAction $update)
    {
        $update->execute($line, $request->validated());

        return redirect()->back();
    }

    public function destroy(QuoteLineDestroyRequest $request, Quote $quote, QuoteLine $line, RemoveQuoteLineAction $remove)
    {
        $remove->execute($line);

        return redirect()->back();
    }
}
