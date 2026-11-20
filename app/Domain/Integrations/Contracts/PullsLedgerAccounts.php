<?php

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\ChangeDetection;
use App\Domain\Integrations\Data\ExternalRecord;
use Carbon\CarbonImmutable;

/**
 * The other side can hand over the accounts a sales line may be booked on,
 * which become ledger accounts here. Only those, as far as the system can
 * tell: a balance sheet account or a blocked one is left out, so nobody picks
 * it for a material.
 */
interface PullsLedgerAccounts
{
    public static function ledgerAccountChanges(): ChangeDetection;

    /**
     * Every such account, or with Delta only those changed since $since. Each
     * record's data is a LedgerAccountData.
     *
     * @return iterable<int, ExternalRecord>
     */
    public function pullLedgerAccounts(?CarbonImmutable $since = null): iterable;

    public function fetchLedgerAccount(string $id): ?ExternalRecord;
}
