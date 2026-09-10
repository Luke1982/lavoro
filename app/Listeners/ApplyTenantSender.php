<?php

namespace App\Listeners;

use App\Models\Company;
use App\Models\GeneralSetting;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * Puts the customer's sender on the customer's post. Without this the address
 * from .env is on top of it, and that is the address of whoever happened to be
 * delivered first.
 */
class ApplyTenantSender
{
    public function handle(MessageSending $event): void
    {
        if (!tenancy()->initialized) {
            return;
        }

        $address = GeneralSetting::get('mail_from_address');

        if (!filled($address)) {
            return;
        }

        /**
         * Only when the sender is still the default from .env. A mail that set
         * a sender itself -- our own invoices, for instance -- stays from the
         * party sending it.
         */
        $current = $event->message->getFrom()[0] ?? null;

        if ($current && $current->getAddress() !== config('mail.from.address')) {
            return;
        }

        $name = GeneralSetting::get('mail_from_name')
            ?: (Company::where('is_main', true)->value('name') ?? config('mail.from.name'));

        $event->message->from(new Address($address, (string) $name));
    }
}
