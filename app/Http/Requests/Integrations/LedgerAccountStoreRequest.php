<?php

namespace App\Http\Requests\Integrations;

use App\Models\LedgerAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * One made here gets a number: a bookkeeping knows its accounts by number,
 * and Exact refuses one without. Only one fetched from Moneybird may lack it.
 */
class LedgerAccountStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('integration.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => [$this->needsCode() ? 'required' : 'nullable', 'string', 'max:32', $this->uniqueCode()],
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['code' => 'nummer', 'name' => 'omschrijving'];
    }

    public function messages(): array
    {
        return ['code.unique' => 'Er is al een grootboekrekening met dit nummer.'];
    }

    protected function needsCode(): bool
    {
        return true;
    }

    protected function uniqueCode(): Unique
    {
        return Rule::unique(LedgerAccount::class, 'code');
    }
}
