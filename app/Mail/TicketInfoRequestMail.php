<?php

namespace App\Mail;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * De vraag om aanvullende informatie, met de knop naar de aanleverpagina eronder.
 *
 * De link staat hier en niet in de tekst die de collega intypt: die tekst gaat
 * door een editor heen en een link die je kunt bewerken is een link die stuk kan.
 *
 * Een kopie in de map Verzonden komt vanzelf: CopyMailToSentFolder luistert op
 * elke verstuurde mail en legt hem via IMAP terug, en bij Graph doet de
 * postbus dat zelf.
 */
class TicketInfoRequestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $rendered_subject,
        public string $body_html,
        public string $upload_url,
        public ?string $expires_on = null,
    ) {}

    /**
     * Het logo gaat als bijlage mee en niet als link naar onze eigen server.
     *
     * Bijna elk mailprogramma blokkeert externe afbeeldingen tot de lezer daar
     * toestemming voor geeft, en dan is het eerste wat een klant van ons ziet een
     * kapot plaatje. Meegestuurd beeld heeft die drempel niet. Het pad wordt
     * doorgegeven en niet de inhoud: de sjabloon hangt hem er met embed() aan, en
     * dat kan alleen daar.
     */
    public function build(): self
    {
        $company = Company::where('is_main', true)->first();
        $disk = Storage::disk('public');

        $has_logo = $company?->logo_path && $disk->exists($company->logo_path);

        return $this->subject($this->rendered_subject)
            ->view('emails.ticket_info_request', [
                'body' => $this->body_html,
                'upload_url' => $this->upload_url,
                'expires_on' => $this->expires_on,
                'company_name' => $company?->name,
                'logo_file' => $has_logo ? $disk->path($company->logo_path) : null,

                /** Voor een voorbeeldweergave, waar geen bericht is om iets aan te hangen. */
                'logo_url' => $has_logo ? asset('storage/' . $company->logo_path) : null,
            ]);
    }
}
