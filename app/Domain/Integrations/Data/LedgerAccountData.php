<?php

namespace App\Domain\Integrations\Data;

use App\Models\LedgerAccount;

/**
 * A revenue account as every connector speaks it: its number and what it is
 * called.
 */
class LedgerAccountData extends RecordData
{
    public const FIELDS = ['name', 'code'];

    public ?string $code = null;

    public static function fields(): array
    {
        return self::FIELDS;
    }

    public static function fromLedgerAccount(LedgerAccount $account): self
    {
        return self::from([...$account->only(self::FIELDS), 'reference' => (string) $account->getKey()]);
    }
}
