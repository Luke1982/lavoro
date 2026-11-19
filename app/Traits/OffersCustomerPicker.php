<?php

namespace App\Traits;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * The customers for the customer picker of a list page. Past the threshold the
 * customer pickers search instead of holding every customer; only the one being
 * filtered on still travels along, or the filter would show a blank where its
 * name goes.
 */
trait OffersCustomerPicker
{
    /** @return array{customers: array<int, array<string, mixed>>, customersUseAjax: bool} */
    protected function customerPicker(?int $filtered_customer_id): array
    {
        $use_ajax = Customer::count() > Customer::COMBO_AJAX_THRESHOLD;

        return [
            'customers' => Customer::query()
                ->when($use_ajax, fn (Builder $query) => $query->whereKey($filtered_customer_id))
                ->orderBy('name')
                ->get(['id', 'name', 'city'])
                ->map->toComboOption()
                ->all(),
            'customersUseAjax' => $use_ajax,
        ];
    }
}
