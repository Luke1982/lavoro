<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Generates the one keypair the browser push identity rests on. Prints it rather
 * than writing it: the private key belongs in the environment of each
 * installation, and a command that edited .env behind somebody's back would be
 * the wrong kind of helpful.
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'notifications:vapid-keys';

    protected $description = 'Genereert een VAPID-sleutelpaar voor browsermeldingen.';

    public function handle(): int
    {
        if (config('webpush.public_key')) {
            $this->warn('Er staan al VAPID-sleutels in de omgeving.');
            $this->line('Vervangen maakt elk bestaand browserabonnement ongeldig; die moeten dan opnieuw toestemming geven.');

            if (!$this->confirm('Toch een nieuw paar genereren?', false)) {
                return self::SUCCESS;
            }
        }

        $keys = VAPID::createVapidKeys();

        $this->info('Zet deze regels in .env:');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY=' . $keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY=' . $keys['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:beheer@' . parse_url((string) config('app.url'), PHP_URL_HOST));
        $this->newLine();
        $this->comment('De private sleutel hoort nergens anders te staan dan hier.');

        return self::SUCCESS;
    }
}
