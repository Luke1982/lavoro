<?php

namespace App\Http\Controllers;

use App\Actions\Quotes\AcceptQuoteAction;
use App\Actions\Quotes\RequestQuoteChangesAction;
use App\Domain\Quotes\QuoteForCustomer;
use App\Http\Requests\QuoteResponseAcceptRequest;
use App\Http\Requests\QuoteResponseChangesRequest;
use App\Models\AccessToken;
use App\Models\Quote;
use App\Models\QuoteVersion;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * The page where a customer without an account looks at a quote and answers
 * it, once: accept, with name and signature, or ask for a change. It shows the
 * version the link went out with, frozen, whatever the quote became since.
 *
 * Whoever gets here came past the middleware with the link; the token is in the
 * container and the quote hangs off it. The page shows what the customer
 * receives on paper, and nothing of what is internal: no purchase prices, no
 * margins, no catalogue.
 */
class QuoteResponseController extends Controller
{
    public function show(AccessToken $access_token): Response
    {
        $quote = $this->quoteFor($access_token);
        $version = QuoteVersion::where('access_token_id', $access_token->id)->first();

        return inertia('Public/QuoteResponsePage', [
            ...($version ? [...$version->snapshot, 'version' => $version->number] : QuoteForCustomer::of($quote)),
            'open' => $quote->statusEnum()->awaitsAnswer(),
            'expires_on' => $access_token->expires_at?->toIso8601String(),
        ]);
    }

    public function accept(
        QuoteResponseAcceptRequest $request,
        AccessToken $access_token,
        AcceptQuoteAction $accept,
    ): RedirectResponse {
        $accept->execute(
            $this->quoteFor($access_token),
            $request->validated('name'),
            $request->validated('signature'),
            $request->validated('options', []),
            $access_token,
        );

        return back()->with('success', 'Dank u wel. Wij hebben uw akkoord ontvangen en nemen contact met u op.');
    }

    public function requestChanges(
        QuoteResponseChangesRequest $request,
        AccessToken $access_token,
        RequestQuoteChangesAction $request_changes,
    ): RedirectResponse {
        $request_changes->execute(
            $this->quoteFor($access_token),
            $request->validated('name'),
            $request->validated('message'),
            $access_token,
        );

        return back()->with('success', 'Dank u wel. Wij passen de offerte aan en sturen u een nieuwe.');
    }

    private function quoteFor(AccessToken $access_token): Quote
    {
        $quote = $access_token->tokenable;

        abort_unless($quote instanceof Quote, 404);

        return $quote->loadMissing(['customer', 'contact', 'lines']);
    }
}
