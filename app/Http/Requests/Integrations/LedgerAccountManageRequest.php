<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The list of ledger accounts, or removing one. They belong with the
 * connections: whoever sets those up keeps them.
 */
class LedgerAccountManageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('integration.manage') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
