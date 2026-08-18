<?php

namespace App\Http\Controllers;

use App\Domain\Signals\Signals;
use App\Domain\Signals\Tickets\CustomerInfoUploaded;
use App\Enums\TicketStatusses;
use App\Http\Requests\CustomerUploadRequest;
use App\Models\AccessToken;
use App\Models\Ticket;
use App\Rules\CustomerUploadFile;
use App\Services\CustomerUploadIntake;
use App\Services\TicketInfoRequestRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Response;

/**
 * De pagina waar een klant zonder account informatie aanlevert bij een storing.
 *
 * Wie hier binnenkomt is door de middleware al langs de link gehaald; het token
 * staat in de container en niet in de url-parameter, en de storing hangt eraan.
 * Wat deze klas erbij doet is wat alleen voor storingen geldt: een gesloten
 * storing neemt niets meer aan.
 *
 * De pagina toont met opzet weinig. Machine, serienummer en wat er gevraagd is —
 * genoeg om te zien dat het over de eigen melding gaat, en niets van wat er intern
 * over die melding is opgeschreven.
 */
class CustomerUploadController extends Controller
{
    public function show(AccessToken $access_token): Response
    {
        $ticket = $this->ticketFor($access_token);

        return inertia('Public/TicketUploadPage', [
            'machine' => TicketInfoRequestRenderer::machine($ticket),
            'serial' => TicketInfoRequestRenderer::serial($ticket),
            'customer' => TicketInfoRequestRenderer::customerName($ticket),
            'requested' => TicketInfoRequestRenderer::labelsFor($access_token->payload['requested'] ?? []),
            'uploaded' => array_values($access_token->payload['uploaded'] ?? []),
            'closed' => $this->isClosed($ticket),
            'expires_on' => $access_token->expires_at?->toIso8601String(),
            'limits' => $this->limits(),
        ]);
    }

    public function store(
        CustomerUploadRequest $request,
        AccessToken $access_token,
        CustomerUploadIntake $intake,
    ): RedirectResponse {
        $ticket = $this->ticketFor($access_token);

        /**
         * Geen abort: een 403 wordt hier bedrijfsbreed omgezet in een omleiding met
         * een algemene tekst, en dan leest de klant niet waaróm er niets gebeurde.
         */
        if ($this->isClosed($ticket)) {
            return back()->withErrors([
                'files' => 'Deze storing is inmiddels afgehandeld. Neem contact met ons op als er nog iets speelt.',
            ]);
        }

        $customer_name = TicketInfoRequestRenderer::customerName($ticket);

        $result = DB::transaction(function () use ($request, $access_token, $ticket, $intake, $customer_name) {
            $result = $intake->receive(
                $ticket,
                $request->file('files') ?? [],
                $request->input('note'),
                $customer_name,
            );

            if ($result->isEmpty()) {
                return $result;
            }

            $this->rememberOnToken($access_token, $result->entries);

            Signals::dispatch(new CustomerInfoUploaded(
                $ticket,
                $result,
                $customer_name,
                $access_token->recipient,
            ));

            return $result;
        });

        if ($result->isEmpty()) {
            return back()->withErrors([
                'files' => 'Er is niets ontvangen. Probeer het opnieuw.',
            ]);
        }

        return back()->with('success', 'Bedankt, wij hebben uw informatie ontvangen.');
    }

    /**
     * Dezelfde grenzen als waar CustomerUploadFile op weigert, per soort uitgesplitst.
     * De pagina rekent er precies zo mee af, zodat een klant een te groot bestand te
     * horen krijgt voordat hij het over mobiel internet omhoog heeft geduwd.
     *
     * @return array<string, mixed>
     */
    private function limits(): array
    {
        return [
            'max_files' => (int) config('customerupload.max_files', 10),
            'note_max' => (int) config('customerupload.note_max', 5000),
            'kinds' => array_map(fn (string $kind) => [
                'kind' => $kind,
                'noun' => CustomerUploadFile::nounFor($kind),
                'max_kb' => (int) config('customerupload.' . $kind . '.max_kb'),
                'extensions' => array_values((array) config('customerupload.' . $kind . '.extensions', [])),
            ], CustomerUploadFile::KINDS),
        ];
    }

    /**
     * Wat deze link verstuurd heeft, bij de link zelf bewaard. Zo weet de pagina
     * het bij een volgend bezoek, zonder dat een klant daarvoor in de storing hoeft
     * te kunnen kijken.
     *
     * @param  array<int, array<string, string>>  $entries
     */
    private function rememberOnToken(AccessToken $access_token, array $entries): void
    {
        $payload = $access_token->payload ?? [];
        $payload['uploaded'] = array_merge($payload['uploaded'] ?? [], $entries);

        $access_token->update(['payload' => $payload]);
        $access_token->markUsed();
    }

    /**
     * De link hangt aan een record, en voor dit doel hoort dat een storing te zijn.
     * Iets anders is geen vergissing van de klant maar van ons, en dan is er hier
     * niets te tonen.
     */
    private function ticketFor(AccessToken $access_token): Ticket
    {
        $ticket = $access_token->tokenable;

        abort_unless($ticket instanceof Ticket, 404);

        $ticket->loadMissing('asset.product.brand', 'asset.product.productType', 'asset.customer');

        return $ticket;
    }

    private function isClosed(Ticket $ticket): bool
    {
        return $ticket->status === TicketStatusses::gesloten->value;
    }
}
