<?php

namespace App\Actions\Integrations;

use App\Domain\Integrations\Sync\Webhooks;
use App\Domain\Signals\Integrations\IntegrationDisconnected;
use App\Domain\Signals\Signals;
use App\Models\IntegrationConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * Removes a connection. The links, the run log and the webhooks go with it;
 * the records do not: customers and materials stay in Lavoro, and in the other
 * system, as they are. Each record it linked is told, in one entry, before the
 * links are gone and nothing knows which records they were.
 *
 * The webhooks are cancelled there first. Once the connection is deleted its
 * webhooks are gone here, and nothing would know what to cancel.
 */
class DisconnectIntegrationAction
{
    public function __construct(private Webhooks $webhooks) {}

    public function execute(IntegrationConnection $connection): void
    {
        $this->webhooks->removeAll($connection);

        DB::transaction(function () use ($connection) {
            $linked = $this->linkedRecords($connection);

            Signals::dispatch(new IntegrationDisconnected($connection, $connection->label(), $linked));
            $connection->delete();
        });
    }

    /**
     * Only their keys: the trail needs to know which records they are, not what
     * is in them, and a first pull can have linked thousands.
     *
     * @return array<int, Model>
     */
    private function linkedRecords(IntegrationConnection $connection): array
    {
        $records = [];

        $links = $connection->links()->toBase()->get(['integrationable_type', 'integrationable_id']);

        foreach ($links->groupBy('integrationable_type') as $type => $of_type) {
            $class = Relation::getMorphedModel($type) ?? $type;

            if (!is_subclass_of($class, Model::class)) {
                continue;
            }

            $key = (new $class)->getKeyName();

            foreach ($of_type->pluck('integrationable_id')->chunk(1000) as $ids) {
                $found = $class::withoutGlobalScopes()->whereKey($ids->values()->all())->get([$key]);
                array_push($records, ...$found->all());
            }
        }

        return $records;
    }
}
