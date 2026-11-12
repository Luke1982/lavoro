<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use Illuminate\Support\Str;

/**
 * Adds a customer.
 *
 * Thin, but the one place a customer comes into being: the form and the
 * assistant both end here, so a customer made from a signed quote is made
 * exactly like one typed in by hand.
 *
 * The attributes are whatever CustomerStoreRequest's rules let through, put
 * through CustomerStoreRequest::normalize(): that request defines the shape.
 */
class CreateCustomerAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes): Customer
    {
        /**
         * SnelStart knows a relation by this id, and sending a werkbon to SnelStart
         * is refused for a customer without one. Handing it out at creation means
         * a customer made here can be invoiced like any imported one.
         */
        if (empty($attributes['snelstart_id'] ?? null)) {
            $attributes['snelstart_id'] = (string) Str::uuid();
        }

        return Customer::create($attributes);
    }
}
