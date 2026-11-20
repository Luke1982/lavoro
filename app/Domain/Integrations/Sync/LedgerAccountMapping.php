<?php

namespace App\Domain\Integrations\Sync;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\Connector;
use App\Domain\Integrations\Contracts\PullsLedgerAccounts;
use App\Domain\Integrations\Contracts\PushesLedgerAccounts;
use App\Domain\Integrations\Data\ExternalRecord;
use App\Domain\Integrations\Data\LedgerAccountData;
use App\Domain\Integrations\Data\RecordData;
use App\Models\LedgerAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class LedgerAccountMapping extends Mapping
{
    /** An account number names one account in a bookkeeping, as it does here. */
    protected const MATCH_ON = [['code']];

    public function area(): Area
    {
        return Area::LedgerAccounts;
    }

    public function fields(Connector $connector): array
    {
        return $connector::ledgerAccountFields();
    }

    public function dataFrom(Model $model): RecordData
    {
        /** @var LedgerAccount $model */
        return LedgerAccountData::fromLedgerAccount($model);
    }

    public function pull(Connector $connector, ?CarbonImmutable $since): iterable
    {
        /** @var PullsLedgerAccounts $connector */
        return $connector->pullLedgerAccounts($since);
    }

    public function fetch(Connector $connector, string $id): ?ExternalRecord
    {
        /** @var PullsLedgerAccounts $connector */
        return $connector->fetchLedgerAccount($id);
    }

    public function create(Connector $connector, RecordData $data): ExternalRecord
    {
        /** @var PushesLedgerAccounts $connector */
        /** @var LedgerAccountData $data */
        return $connector->createLedgerAccount($data);
    }

    public function update(Connector $connector, string $id, RecordData $data): ExternalRecord
    {
        /** @var PushesLedgerAccounts $connector */
        /** @var LedgerAccountData $data */
        return $connector->updateLedgerAccount($id, $data);
    }
}
