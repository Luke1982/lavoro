<?php

namespace App\Http\Controllers;

use App\Actions\Quotes\AddProductToQuoteAction;
use App\Actions\Quotes\AddQuoteLineAction;
use App\Actions\Quotes\RemoveQuoteLineAction;
use App\Actions\Quotes\ReorderQuoteLinesAction;
use App\Actions\Quotes\ToggleQuoteAlternativeAction;
use App\Actions\Quotes\UpdateQuoteLineAction;
use App\Enums\QuoteLineKinds;
use App\Http\Requests\QuoteLineAlternativeRequest;
use App\Http\Requests\QuoteLineDestroyRequest;
use App\Http\Requests\QuoteLinesOrderRequest;
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
            $request->boolean('is_optional'),
        );

        return redirect()->back();
    }

    public function store(QuoteLineStoreRequest $request, Quote $quote, AddQuoteLineAction $add)
    {
        $add->execute($quote, $request->enum('kind', QuoteLineKinds::class), $request->validated());

        return redirect()->back();
    }

    public function reorder(QuoteLinesOrderRequest $request, Quote $quote, ReorderQuoteLinesAction $reorder)
    {
        $reorder->execute($quote, array_map('intval', $request->validated('line_ids')));

        return redirect()->back();
    }

    public function update(
        QuoteLineUpdateRequest $request,
        Quote $quote,
        QuoteLine $line,
        UpdateQuoteLineAction $update,
    ) {
        $update->execute($line, $request->validated());

        return redirect()->back();
    }

    /** Makes the line an alternative to the one above it, or a line of its own again. */
    public function alternative(
        QuoteLineAlternativeRequest $request,
        Quote $quote,
        QuoteLine $line,
        ToggleQuoteAlternativeAction $toggle,
    ) {
        $toggle->execute($line);

        return redirect()->back();
    }

    public function destroy(
        QuoteLineDestroyRequest $request,
        Quote $quote,
        QuoteLine $line,
        RemoveQuoteLineAction $remove,
    ) {
        $remove->execute($line);

        return redirect()->back();
    }
}
