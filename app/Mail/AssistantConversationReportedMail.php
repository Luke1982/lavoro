<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * A reported assistant conversation, delivered instead of waiting to be found.
 *
 * The report lands on a private disk on the server, which is exactly where
 * nobody looks. Somebody pressing "Gesprek melden" is saying something went
 * wrong; that belongs in an inbox, with the file attached, so the person who
 * investigates has the tool arguments and results in hand without shelling in.
 */
class AssistantConversationReportedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        /** Not called markdown: the parent Mailable already owns that property. */
        private readonly string $report,
        private readonly string $filename,
        private readonly string $reporter,
    ) {}

    public function build(): self
    {
        return $this->subject('Gemeld assistent-gesprek van ' . $this->reporter)
            ->html(
                '<p>' . e($this->reporter) . ' heeft een gesprek met de assistent gemeld.</p>'
                . '<p>Het volledige gesprek zit als bijlage bij deze mail, inclusief wat de '
                . 'tools werden meegegeven en teruggaven — daar zitten meestal de fouten '
                . 'die in de antwoordtekst goed lijken.</p>'
            )
            ->attachData($this->report, $this->filename, ['mime' => 'text/markdown']);
    }
}
