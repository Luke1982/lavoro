<?php

namespace App\Http\Controllers;

use App\Domain\Access\LinkMailer;
use App\Domain\Access\LinkNotSent;
use App\Domain\Signals\Signals;
use App\Domain\Signals\Tickets\CustomerInfoRequested;
use App\Enums\AccessTokenPurpose;
use App\Enums\TicketStatusses;
use App\Http\Requests\TicketInfoRequestReadRequest;
use App\Http\Requests\TicketInfoRequestSendRequest;
use App\Mail\TicketInfoRequestMail;
use App\Models\Ticket;
use App\Services\TicketInfoRequestRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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
     * Eerst de link en de mail, dan pas de gevolgen. Gaat de mail niet weg, dan
     * trekt LinkMailer de link in en is er verder niets gebeurd: geen regel op
     * de tijdlijn die iets belooft wat de klant nooit gelezen heeft, en een
     * storing die niet op wachten staat terwijl er nergens op gewacht wordt.
     */
    public function send(TicketInfoRequestSendRequest $request, Ticket $ticket, LinkMailer $mailer): JsonResponse
    {
        $data = $request->validated();
        $requested = $request->requested();

        try {
            $issued = $mailer->send(
                $ticket,
                AccessTokenPurpose::ticket_customer_upload,
                $data['to'],
                fn (string $url, ?string $expires_on) => new TicketInfoRequestMail(
                    $data['subject'],
                    $data['body'],
                    $url,
                    $expires_on,
                ),
                ['requested' => $requested],
            );
        } catch (LinkNotSent) {
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
