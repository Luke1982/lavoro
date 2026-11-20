<?php

namespace App\Domain\Integrations\Sync;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\Capability;
use App\Domain\Integrations\Contracts\ReceivesWebhooks;
use App\Exceptions\Refusal;
use App\Models\Central\IntegrationWebhookLookup;
use App\Models\IntegrationConnection;
use App\Models\IntegrationWebhook;
use Illuminate\Support\Str;

/**
 * Keeps the webhooks of a connection matching its settings: one for every area
 * it reads and its system can send notices for, renewed before it runs out,
 * and gone when the area is switched off or the connection removed.
 *
 * When the other side refuses, that is written on the connection and left: the
 * nightly pull still runs, so a missing webhook only means changes arrive
 * later, not that they are lost. The next renewal round tries again. A fault
 * of our own is not caught here.
 */
class Webhooks
{
    /** Renewed when it runs out within this many hours. */
    public const RENEW_WITHIN_HOURS = 24;

    public function ensure(IntegrationConnection $connection): void
    {
        if (!$connection->can(Capability::Webhooks)) {
            return;
        }

        /** @var class-string<ReceivesWebhooks> $connector */
        $connector = $connection->connectorClass();
        $wanted = array_filter($connector::webhookAreas(), fn (Area $area) => $connection->reads($area));
        $webhooks = $connection->webhooks()->get()->keyBy(fn (IntegrationWebhook $webhook) => $webhook->area->value);

        foreach ($webhooks as $key => $webhook) {
            if (!in_array($webhook->area, $wanted, true) || $webhook->scope !== $this->scope($connection)) {
                $this->remove($webhook);
                $webhooks->forget($key);
            }
        }

        foreach ($wanted as $area) {
            $webhook = $webhooks->get($area->value);

            if (!$webhook || $this->due($webhook)) {
                $this->subscribe($connection, $area, $webhook);
            }
        }
    }

    public function removeAll(IntegrationConnection $connection): void
    {
        foreach ($connection->webhooks()->get() as $webhook) {
            $this->remove($webhook);
        }
    }

    public static function url(IntegrationWebhook $webhook): string
    {
        return route('integrations.webhook', ['token' => $webhook->token]);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** What webhooks of this connection are made for now; empty while that is not chosen yet. */
    private function scope(IntegrationConnection $connection): ?string
    {
        try {
            /** @var ReceivesWebhooks $connector */
            $connector = $connection->connector();

            return $connector->webhookScope();
        } catch (Refusal) {
            return null;
        }
    }

    /** Never subscribed (the other side refused last time), or running out soon. */
    private function due(IntegrationWebhook $webhook): bool
    {
        return $webhook->external_id === null
            || ($webhook->expires_at && $webhook->expires_at->isBefore(now()->addHours(self::RENEW_WITHIN_HOURS)));
    }

    private function subscribe(IntegrationConnection $connection, Area $area, ?IntegrationWebhook $webhook): void
    {
        $webhook ??= $this->create($connection, $area);

        try {
            /** @var ReceivesWebhooks $connector */
            $connector = $connection->connector();
            $subscription = $connector->subscribe($area, self::url($webhook), $webhook);
        } catch (Refusal $e) {
            $connection->noteFailure('Webhook voor ' . mb_strtolower($area->label()) . ' niet aangemeld: ' . $e->getMessage());

            return;
        }

        $webhook->update([
            'external_id' => $subscription->id,
            'expires_at' => $subscription->expires_at,
            'signing_secret' => $subscription->signing_secret ?? $webhook->signing_secret,
            'scope' => $this->scope($connection),
        ]);
    }

    private function create(IntegrationConnection $connection, Area $area): IntegrationWebhook
    {
        $webhook = $connection->webhooks()->create(['area' => $area, 'token' => Str::random(48)]);

        IntegrationWebhookLookup::create([
            'token_hash' => self::hash($webhook->token),
            'tenant_id' => tenancy()->tenant->getTenantKey(),
            'integration_webhook_id' => $webhook->id,
        ]);

        return $webhook;
    }

    /** Their side may already have dropped it; ours goes regardless. */
    private function remove(IntegrationWebhook $webhook): void
    {
        try {
            /** @var ReceivesWebhooks $connector */
            $connector = $webhook->integrationConnection->connector();
            $connector->unsubscribe($webhook);
        } catch (Refusal) {
        }

        IntegrationWebhookLookup::whereKey(self::hash($webhook->token))->delete();
        $webhook->delete();
    }
}
