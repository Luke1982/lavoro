<?php

namespace App\Listeners\Customers;

use App\Domain\Signals\Customers\ContactUnlinkedFromCustomer;

/**
 * A contact person is only ever picked from the record's own customer. Once the
 * person leaves that customer, its werkbonnen, projects and quotes let go of
 * them, so a standard e-mail no longer greets someone who works elsewhere.
 */
class ReleaseUnlinkedContact
{
    public function handle(ContactUnlinkedFromCustomer $signal): void
    {
        $signal->contact->release($signal->customer);
    }
}
