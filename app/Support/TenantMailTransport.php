<?php

namespace App\Support;

use App\Exceptions\GraphNotConfigured;
use App\Exceptions\MailNotConfigured;
use App\Mail\Transports\GraphTransport;
use App\Models\GeneralSetting;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds the logged in customer's mailer. There is deliberately no falling back
 * to .env: sending mail from another company's mailbox puts the wrong sender on
 * a customer's post, and that is worse than not sending -- there is no error,
 * the message simply comes from someone else.
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
         * Port 465 speaks TLS straight away, 587 starts without and switches
         * over with STARTTLS. Turn that around and you get no polite error but
         * a connection that hangs, so it is derived from the port here unless
         * it is set explicitly.
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
