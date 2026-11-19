<?php

namespace App\Http\Controllers;

use App\Domain\Integrations\Contracts\ReceivesWebhooks;
use App\Domain\Integrations\Data\ChangeNotice;
use App\Domain\Integrations\Sync\Webhooks;
use App\Jobs\Integrations\PullRecordJob;
use App\Models\Central\IntegrationWebhookLookup;
use App\Models\IntegrationWebhook;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where linked systems report a change. There is no session and no login: the
 * token in the address is the credential, and the customer is found from it in
 * the central lookup, never from anything else in the request.
 *
 * The work is not done here. Each notice becomes a job that fetches the record
 * itself, so what the caller sent is never taken for the record's contents.
 */
class IntegrationWebhookController extends Controller
{
    public function handle(Request $request, string $token): Response
    {
        $lookup = IntegrationWebhookLookup::find(Webhooks::hash($token));
        $tenant = $lookup ? Tenant::on('central')->find($lookup->tenant_id) : null;

        if (!$tenant) {
            return response('', 404);
        }

        tenancy()->initialize($tenant);

        $webhook = IntegrationWebhook::find($lookup->integration_webhook_id);

        if (!$webhook || !hash_equals($webhook->token, $token) || !$webhook->integrationConnection?->is_active) {
            return response('', 404);
        }

        $connector = $webhook->integrationConnection->connector();

        if (!$connector instanceof ReceivesWebhooks) {
            return response('', 404);
        }

        $handshake = $connector->handshake($request, $webhook);

        if ($handshake) {
            return $handshake;
        }

        $notices = collect($connector->notices($request, $webhook))
            ->unique(fn (ChangeNotice $notice) => $notice->area->value . '|' . $notice->external_id);

        foreach ($notices as $notice) {
            PullRecordJob::dispatch($webhook->integration_connection_id, $notice->area->value, $notice->external_id);
        }

        return response('', 202);
    }
}
