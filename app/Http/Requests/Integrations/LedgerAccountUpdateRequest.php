<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Validation\Rules\Unique;

/**
 * One fetched without a number (Moneybird) can be renamed without being given
 * one; one that has a number keeps having one.
 */
class LedgerAccountUpdateRequest extends LedgerAccountStoreRequest
{
    protected function needsCode(): bool
    {
        return filled($this->route('ledger_account')->code);
    }

    protected function uniqueCode(): Unique
    {
        return parent::uniqueCode()->ignore($this->route('ledger_account'));
    }
}
