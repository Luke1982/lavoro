<?php

namespace App\Http\Requests\Concerns;

use App\Models\Project;
use App\Models\User;
use App\Rules\ContactOfCustomer;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The fields a project is made and edited with, checked the same way on both. A
 * location and a contact person come from the project's own customer; the budget
 * amount is project money and only who manages a project's finances may set it.
 *
 * @mixin FormRequest
 */
trait ValidatesProjectFields
{
    /** @return array<string, array<int, mixed>> */
    protected function projectFieldRules(?int $customer_id, bool $may_set_budget_amount): array
    {
        return [
            'description' => ['nullable', 'string'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_id' => [
                'sometimes',
                'nullable',
                Rule::exists('locations', 'id')->where('customer_id', $customer_id),
            ],
            'contact_id' => ['sometimes', 'nullable', new ContactOfCustomer($customer_id, 'dit project')],
            'budget_hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'budget_amount' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
                'max:9999999999',
                Rule::prohibitedIf(!$may_set_budget_amount),
            ],
        ];
    }

    protected function projectLeaderRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (!User::query()->canLeadProjects()->whereKey($value)->exists()) {
                $fail('De geselecteerde projectleider mag geen projecten leiden.');
            }
        };
    }

    /** @return array<string, string> */
    protected function projectFieldMessages(): array
    {
        return [
            'location_id.exists' => 'Deze locatie hoort niet bij de klant van dit project.',
            'budget_amount.prohibited' => 'Alleen wie de financiën van projecten beheert mag het begrote bedrag invullen.',
            'end_date.after_or_equal' => 'De einddatum ligt vóór de startdatum.',
            'start_date.before_or_equal' => 'De startdatum ligt na de einddatum.',
        ];
    }

    protected function mayManageFinancials(?Project $project = null): bool
    {
        return $this->user()->can('manageFinancials', $project ?? new Project);
    }
}
