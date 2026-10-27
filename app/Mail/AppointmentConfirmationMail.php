<?php

namespace App\Mail;

use App\Mail\Concerns\ShowsCompanyLogo;
use App\Models\Company;
use App\Models\Event;
use App\Models\ServiceOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AppointmentConfirmationMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use ShowsCompanyLogo;

    public function __construct(public Event $event, public ServiceOrder $serviceOrder)
    {
        //
    }

    public function build(): self
    {
        $company = Company::main();

        return $this->subject('Afspraakbevestiging #' . $this->serviceOrder->id)
            ->from(config('mail.from.address'), $company?->name ?? config('app.name'))
            ->view('emails.event.appointment_confirmation', [
                'event' => $this->event,
                'serviceOrder' => $this->serviceOrder,
                'company' => $company,
                ...$this->companyLogo(),
            ]);
    }
}
