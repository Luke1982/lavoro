<?php

namespace App\Http\Controllers;

use App\Actions\Quotes\AcceptQuoteAction;
use App\Actions\Quotes\ConvertQuoteToServiceOrderAction;
use App\Actions\Quotes\CreateProjectFromQuoteAction;
use App\Actions\Quotes\SendQuoteAction;
use App\Actions\Quotes\StartNewQuoteVersionAction;
use App\Http\Requests\QuoteAcceptRequest;
use App\Http\Requests\QuoteConvertRequest;
use App\Http\Requests\QuoteProjectStoreRequest;
use App\Http\Requests\QuoteSendRequest;
use App\Http\Requests\QuoteVersionStartRequest;
use App\Models\Quote;
use Illuminate\Support\Facades\DB;

/** What happens to a quote between making it and carrying it out: sending, a new version, the yes, and the werkbon or project. */
class QuoteDecisionController extends Controller
{
    public function send(QuoteSendRequest $request, Quote $quote, SendQuoteAction $send)
    {
        $send->execute($quote, ...$request->safe()->only(['to', 'subject', 'body']));

        return redirect()->back()
            ->with('success', 'Offerte ' . $quote->number . ' verstuurd aan ' . $request->validated('to') . '.');
    }

    /** Accepting and, when asked, the werkbon in one go: a werkbon that fails takes the acceptance back with it. */
    public function accept(
        QuoteAcceptRequest $request,
        Quote $quote,
        AcceptQuoteAction $accept,
        ConvertQuoteToServiceOrderAction $convert,
    ) {
        $order = DB::transaction(function () use ($request, $quote, $accept, $convert) {
            $accept->execute(
                $quote,
                $request->validated('name'),
                $request->validated('signature'),
                $request->validated('options', []),
                starts_within_cooling_off: $request->boolean('starts_within_cooling_off'),
            );

            return $request->boolean('make_service_order') ? $convert->execute($quote) : null;
        });

        if ($order === null) {
            return redirect()->back()->with('success', 'Offerte ' . $quote->number . ' geaccepteerd.');
        }

        return redirect()->route('serviceorders.show', $order)
            ->with('success', 'Offerte geaccepteerd en werkbon ' . $order->number . ' gemaakt.');
    }

    /** What follows is a new version: the customer's copy keeps its number, the next send gets the next one. */
    public function newVersion(QuoteVersionStartRequest $request, Quote $quote, StartNewQuoteVersionAction $start)
    {
        $start->execute($quote);

        return redirect()->back()
            ->with('success', 'Je werkt nu aan versie ' . $quote->version . ' van offerte ' . $quote->number . '.');
    }

    public function project(QuoteProjectStoreRequest $request, Quote $quote, CreateProjectFromQuoteAction $create)
    {
        $project = $create->execute($quote, $request->validated());

        return redirect()->route('projects.show', $project)
            ->with('success', 'Project ' . $project->number . ' gemaakt van offerte ' . $quote->number . '.');
    }

    public function convert(QuoteConvertRequest $request, Quote $quote, ConvertQuoteToServiceOrderAction $convert)
    {
        $order = $convert->execute($quote);

        return redirect()->route('serviceorders.show', $order)
            ->with('success', 'Werkbon ' . $order->number . ' gemaakt van offerte ' . $quote->number . '.');
    }
}
