<?php

namespace App\Http\Controllers;

use App\Domain\Signals\Signals;
use App\Domain\Signals\Tickets\CustomerInfoRequested;
use App\Enums\AccessTokenPurpose;
use App\Enums\TicketStatusses;
use App\Http\Requests\TicketInfoRequestReadRequest;
use App\Http\Requests\TicketInfoRequestSendRequest;
use App\Mail\TicketInfoRequestMail;
use App\Models\AccessToken;
use App\Models\Ticket;
use App\Services\TicketInfoRequestRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * De klant om aanvullende informatie vragen bij een storing.
 *
 * Het token wordt pas bij het versturen uitgegeven: een aanvraag die iemand
 * halverwege wegklikt hoort geen link achter te laten die het nog doet.
 */
class TicketInfoRequestController extends Controller
{
    /** Wat er in het scherm staat voordat iemand er iets aan verandert. */
    public function defaults(TicketInfoRequestReadRequest $request, Ticket $ticket): JsonResponse
    {
        $ticket->load('asset.product.brand', 'asset.product.productType', 'asset.customer');

        return response()->json([
            'to' => TicketInfoRequestRenderer::defaultRecipient($ticket),
            'subject' => TicketInfoRequestRenderer::subject(),
            'body' => TicketInfoRequestRenderer::body($ticket),
            'options' => TicketInfoRequestRenderer::options(),
            'requested' => TicketInfoRequestRenderer::DEFAULT_REQUESTED,
        ]);
    }

    /**
     * Eerst de link, dan de mail, dan pas de gevolgen.
     *
     * De mail staat met opzet buiten elke transactie: een smtp-verbinding duurt
     * seconden en zolang zou de database op slot staan voor iets dat helemaal
     * buiten de database gebeurt.
     *
     * Wat er dan overblijft is de vraag wat er waar is als de mail niet weggaat.
     * De link wordt ingetrokken en verder is er niets gebeurd: geen regel op de
     * tijdlijn die iets belooft wat de klant nooit gelezen heeft, en een storing
     * die niet op wachten staat terwijl er nergens op gewacht wordt.
     */
    public function send(TicketInfoRequestSendRequest $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validated();
        $requested = $request->requested();

        $issued = AccessToken::issue(
            $ticket,
            AccessTokenPurpose::ticket_customer_upload,
            $data['to'],
            ['requested' => $requested],
        );

        try {
            Mail::to($data['to'])->send(new TicketInfoRequestMail(
                $data['subject'],
                $data['body'],
                $issued->url(),
                $issued->token->expires_at?->format('d-m-Y'),
            ));
        } catch (Throwable $e) {
            $issued->token->revoke();
            report($e);

            return response()->json([
                'message' => 'De e-mail kon niet verzonden worden. De aanvraag is niet doorgegaan.',
            ], 500);
        }

        DB::transaction(function () use ($ticket, $data, $requested, $issued) {
            Signals::dispatch(new CustomerInfoRequested(
                $ticket,
                $data['to'],
                $requested,
                $issued->token->expires_at,
            ));

            $ticket->update(['status' => TicketStatusses::wacht_op_klant->value]);
        });

        return response()->json([
            'message' => 'Aanvraag verzonden aan ' . $data['to'],
            'status' => TicketStatusses::wacht_op_klant->value,
        ]);
    }
}
