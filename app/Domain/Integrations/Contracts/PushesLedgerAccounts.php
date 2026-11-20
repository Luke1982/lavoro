<?php

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\Data\ExternalRecord;
use App\Domain\Integrations\Data\LedgerAccountData;

/**
 * The other side takes ledger accounts made or changed in Lavoro, as revenue
 * accounts. Like PushesCustomers: both return the account as it now stands
 * there, and an update of one that is gone there throws RecordNotFound.
 */
interface PushesLedgerAccounts
{
    public function createLedgerAccount(LedgerAccountData $account): ExternalRecord;

    public function updateLedgerAccount(string $id, LedgerAccountData $account): ExternalRecord;
}
