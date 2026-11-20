<?php

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\Data\ChangeNotice;
use App\Domain\Integrations\Data\WebhookSubscription;
use App\Models\IntegrationWebhook;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The other side can call Lavoro when something changes, instead of waiting
 * for the next pull. The pull keeps running beside it as a safety net.
 *
 * The address it calls carries a token that is Lavoro's own; it is how the
 * call finds its customer, and the connector checks whatever proof of origin
 * its system adds on top.
 */
interface ReceivesWebhooks
{
    /** @return array<int, Area> The areas it can send notices for. */
    public static function webhookAreas(): array;

    /**
     * What a webhook is made for there: the administration, division or
     * company. A webhook made for another one is cancelled and made again.
     */
    public function webhookScope(): string;

    /** Asks the other side to call $url for changes in this area; also used to renew. */
    public function subscribe(Area $area, string $url, IntegrationWebhook $webhook): WebhookSubscription;

    public function unsubscribe(IntegrationWebhook $webhook): void;

    /**
     * Some systems first call to see whether the address answers. Return the
     * answer they expect, or null when this request is a real notice.
     */
    public function handshake(Request $request, IntegrationWebhook $webhook): ?Response;

    /** @return array<int, ChangeNotice> */
    public function notices(Request $request, IntegrationWebhook $webhook): array;
}
