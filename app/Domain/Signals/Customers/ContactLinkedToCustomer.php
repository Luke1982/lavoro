<?php

namespace App\Domain\Signals\Customers;

use App\Domain\Signals\BaseSignal;
use App\Models\Contact;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;

/**
 * A contact person now belongs to this customer as well. Not raised when the
 * contact is created for the customer: that entry already lands on the customer.
 */
class ContactLinkedToCustomer extends BaseSignal
{
    public function __construct(
        public Customer $customer,
        public Contact $contact,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'customer.contact_linked';
    }

    public static function label(): string
    {
        return 'Contactpersoon gekoppeld';
    }

    public function subject(): Model
    {
        return $this->customer;
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    public function activityDescription(): ?string
    {
        return 'Contactpersoon ' . trim($this->contact->full_name) . ' gekoppeld';
    }

    public function activityMetadata(): ?array
    {
        return ['contact_id' => $this->contact->id];
    }
}
