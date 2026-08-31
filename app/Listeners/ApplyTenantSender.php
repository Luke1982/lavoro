<?php

namespace App\Listeners;

use App\Models\Company;
use App\Models\GeneralSetting;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * Zet de afzender van de klant op de post van de klant. Zonder dit staat er
 * het adres uit de .env boven, en dat is het adres van wie er toevallig als
 * eerste is opgeleverd.
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
         * Alleen als de afzender nog de standaard uit de .env is. Een mail die
         * zelf een afzender heeft gezet — onze eigen facturen bijvoorbeeld —
         * blijft zo van de partij die hem stuurt.
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
