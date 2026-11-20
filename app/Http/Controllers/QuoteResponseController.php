<?php

namespace App\Http\Controllers;

use App\Actions\Quotes\AcceptQuoteAction;
use App\Actions\Quotes\DeclineQuoteAction;
use App\Actions\Quotes\RecordQuoteViewAction;
use App\Actions\Quotes\RequestQuoteChangesAction;
use App\Domain\Quotes\QuoteFiles;
use App\Domain\Quotes\QuoteForCustomer;
use App\Http\Requests\QuoteResponseAcceptRequest;
use App\Http\Requests\QuoteResponseChangesRequest;
use App\Http\Requests\QuoteResponseDeclineRequest;
use App\Models\AccessToken;
use App\Models\Quote;
use App\Models\QuoteLostReason;
use App\Models\QuoteVersion;
use Illuminate\Http\RedirectResponse;
use Inertia\Response as Page;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page where a customer without an account looks at a quote and answers
 * it, once: accept, with name and signature, ask for a change, or decline. It
 * shows the version the link went out with, frozen, whatever the quote became
 * since; the PDF and the general terms come from that same version.
 *
 * Whoever gets here came past the middleware with the link; the token is in the
 * container and the quote hangs off it. The page shows what the customer
 * receives on paper, and nothing of what is internal: no purchase prices, no
 * margins, no catalogue.
 */
class QuoteResponseController extends Controller
{
    public function show(AccessToken $access_token, RecordQuoteViewAction $record_view): Page
    {
        $quote = $this->quoteFor($access_token);
        $version = QuoteVersion::shownBy($access_token);

        if ($version !== null) {
            $record_view->execute($quote, $version);
        }

        return inertia('Public/QuoteResponsePage', [
            ...QuoteForCustomer::forPage($this->documentFor($access_token, $version)),
            'open' => $quote->statusEnum()->awaitsAnswer(),
            'expires_on' => $access_token->expires_at?->toIso8601String(),
            'lost_reasons' => QuoteLostReason::where('customer_can_pick', true)
                ->orderBy('position')
                ->get(['id', 'name']),
        ]);
    }

    public function pdf(AccessToken $access_token): Response
    {
        return QuoteFiles::inline(QuoteFiles::pdf($this->documentFor($access_token)));
    }

    public function terms(AccessToken $access_token): Response
    {
        return QuoteFiles::open($this->documentFor($access_token)['terms']);
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
            $request->boolean('starts_within_cooling_off'),
        );

        return back()
            ->with('success', 'Dank u wel. Wij hebben uw akkoord ontvangen en sturen u een bevestiging per e-mail.');
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

    public function decline(
        QuoteResponseDeclineRequest $request,
        AccessToken $access_token,
        DeclineQuoteAction $decline,
    ): RedirectResponse {
        $decline->execute(
            $this->quoteFor($access_token),
            QuoteLostReason::find($request->validated('lost_reason_id')),
            $request->validated('message'),
            $request->validated('name'),
            $access_token,
        );

        return back()->with('success', 'Dank u wel voor uw reactie. Jammer dat we u deze keer niet kunnen helpen.');
    }

    private function quoteFor(AccessToken $access_token): Quote
    {
        $quote = $access_token->tokenable;

        abort_unless($quote instanceof Quote, 404);

        return $quote->loadMissing(['customer', 'contact', 'lines']);
    }

    /**
     * The version the link shows, or the quote as it stands for a link sent
     * before there were versions.
     *
     * @return array<string, mixed>
     */
    private function documentFor(AccessToken $access_token, ?QuoteVersion $version = null): array
    {
        $version ??= QuoteVersion::shownBy($access_token);

        return $version?->document() ?? QuoteForCustomer::of($this->quoteFor($access_token));
    }
}
