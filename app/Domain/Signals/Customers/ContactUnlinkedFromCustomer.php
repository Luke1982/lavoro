<?php

namespace App\Domain\Signals\Customers;

use App\Domain\Signals\BaseSignal;
use App\Models\Contact;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;

/**
 * A contact person no longer belongs to this customer. The person still exists,
 * possibly at another customer; only the link is gone.
 */
class ContactUnlinkedFromCustomer extends BaseSignal
{
    public function __construct(
        public Customer $customer,
        public Contact $contact,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'customer.contact_unlinked';
    }

    public static function label(): string
    {
        return 'Contactpersoon ontkoppeld';
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
        return 'Contactpersoon ' . trim($this->contact->full_name) . ' ontkoppeld';
    }

    public function activityMetadata(): ?array
    {
        return ['contact_id' => $this->contact->id];
    }
}
