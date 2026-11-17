<?php

namespace App\Domain\Tray\Paste;

use App\Exceptions\Refusal;
use App\Models\Customer;
use App\Models\Location;

/**
 * Machines dropped on a location: they move to it, and to its customer when
 * that is someone else. The same move as dropping them on the customer, with
 * the location already picked.
 */
class AssetsOntoLocation extends AssetsOntoCustomer
{
    protected function customer(Paste $paste): Customer
    {
        return $this->destination($paste)->customer
            ?? throw new Refusal('Deze locatie hoort bij geen klant.');
    }

    protected function destination(Paste $paste): Location
    {
        return DropTarget::location($paste);
    }
}
