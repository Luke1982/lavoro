<?php

namespace App\Http\Controllers;

use App\Actions\Quotes\CreateQuoteAction;
use App\Domain\Quotes\QuoteContent;
use App\Domain\Quotes\QuoteOrigins;
use App\Http\Requests\QuoteDuplicateRequest;
use App\Http\Requests\QuoteOriginReadRequest;
use App\Http\Requests\QuoteTemplateStoreRequest;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteTemplate;
use Illuminate\Http\JsonResponse;

/**
 * The ways a quote starts from something that is already there: a werkbon or a
 * storing, another quote, or a template a quote was kept as.
 */
class QuoteStartController extends Controller
{
    /** What the form for a quote from a werkbon or storing starts with, and the lines it can take over. */
    public function origin(QuoteOriginReadRequest $request): JsonResponse
    {
        $origin = $request->origin();
        $heading = QuoteOrigins::heading($origin);

        return response()->json([
            ...$heading,
            'customer' => Customer::find($heading['customer_id'])?->only(['id', 'name']),
            'label' => QuoteOrigins::label($origin),
            'choices' => QuoteOrigins::choices($origin),
        ]);
    }

    public function duplicate(QuoteDuplicateRequest $request, Quote $quote, CreateQuoteAction $create)
    {
        $copy = $create->execute($request->validated(), fn () => QuoteContent::of($quote), $quote);

        return redirect()->route('quotes.show', $copy)
            ->with('success', 'Offerte ' . $copy->number . ' gemaakt als kopie van ' . $quote->number . '.');
    }

    public function saveAsTemplate(QuoteTemplateStoreRequest $request, Quote $quote)
    {
        QuoteTemplate::create([
            'name' => $request->validated('name'),
            'content' => QuoteContent::of($quote),
            'created_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', "Sjabloon '" . $request->validated('name') . "' bewaard.");
    }
}
