<?php

namespace App\Support;

use App\Exceptions\GraphNotConfigured;
use App\Exceptions\MailNotConfigured;
use App\Mail\Transports\GraphTransport;
use App\Models\GeneralSetting;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Bouwt de mailer van de ingelogde klant. Er is bewust geen terugval op de
 * .env: mail versturen uit de mailbox van een ander bedrijf zet de verkeerde
 * afzender op de post van een klant, en dat is erger dan niet versturen — er
 * komt geen foutmelding, het bericht komt gewoon van iemand anders.
 */
final class TenantMailTransport
{
    public function make(): TransportInterface
    {
        if (!tenancy()->initialized) {
            throw new MailNotConfigured;
        }

        return match (GeneralSetting::get('mail_transport', 'graph')) {
            'smtp' => $this->smtp(),
            'log' => Transport::fromDsn('null://null'),
            default => $this->graph(),
        };
    }

    private function smtp(): TransportInterface
    {
        $host = GeneralSetting::get('mail_smtp_host');
        $username = GeneralSetting::get('mail_smtp_username');
        $password = GeneralSetting::get('mail_smtp_password');

        if (!filled($host) || !filled($username) || !filled($password)) {
            throw new MailNotConfigured;
        }

        $port = (int) GeneralSetting::get('mail_smtp_port', 587);

        /**
         * Poort 465 spreekt meteen TLS, 587 begint zonder en schakelt om met
         * STARTTLS. Wie dat omdraait krijgt geen nette fout maar een verbinding
         * die blijft hangen, dus wordt het hier uit de poort afgeleid tenzij
         * het expliciet is gezet.
         */
        $scheme = GeneralSetting::get('mail_smtp_scheme') ?: ($port === 465 ? 'smtps' : 'smtp');

        $dsn = sprintf(
            '%s://%s:%s@%s:%d',
            $scheme,
            rawurlencode((string) $username),
            rawurlencode((string) $password),
            rawurlencode((string) $host),
            $port,
        );

        return Transport::fromDsn($dsn);
    }

    private function graph(): TransportInterface
    {
        $azure_tenant = GeneralSetting::get('graph_azure_tenant_id');
        $client_id = GeneralSetting::get('graph_client_id');
        $secret = GeneralSetting::get('graph_client_secret');
        $user_id = GeneralSetting::get('graph_user_id');

        if (!filled($azure_tenant) || !filled($client_id) || !filled($secret) || !filled($user_id)) {
            throw new GraphNotConfigured;
        }

        return new GraphTransport(
            tenantId: $azure_tenant,
            clientId: $client_id,
            clientSecret: $secret,
            fromAddress: GeneralSetting::get('mail_from_address', $user_id),
            userId: $user_id,
            graphEndpoint: config('services.graph.endpoint'),
            dispatcher: app('events'),
            logger: app('log')->channel(),
        );
    }
}
