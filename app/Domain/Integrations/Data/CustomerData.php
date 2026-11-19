<?php

namespace App\Domain\Integrations\Data;

use App\Models\Customer;

/**
 * A customer as every connector speaks it: the fields of a Lavoro customer
 * that also mean something in a bookkeeping system.
 */
class CustomerData extends RecordData
{
    public const FIELDS = [
        'name', 'contactname', 'email', 'invoice_email', 'quotes_email', 'phone', 'mobile', 'website',
        'address', 'postal_code', 'city', 'country',
        'postal_address', 'postal_postal_code', 'postal_city', 'postal_country',
        'iban', 'vat_number', 'chamber_of_commerce_number',
    ];

    public ?string $contactname = null;

    public ?string $email = null;

    public ?string $invoice_email = null;

    public ?string $quotes_email = null;

    public ?string $phone = null;

    public ?string $mobile = null;

    public ?string $website = null;

    public ?string $address = null;

    public ?string $postal_code = null;

    public ?string $city = null;

    public ?string $country = null;

    public ?string $postal_address = null;

    public ?string $postal_postal_code = null;

    public ?string $postal_city = null;

    public ?string $postal_country = null;

    public ?string $iban = null;

    public ?string $vat_number = null;

    public ?string $chamber_of_commerce_number = null;

    public static function fields(): array
    {
        return self::FIELDS;
    }

    public static function fromCustomer(Customer $customer): self
    {
        return self::from([...$customer->only(self::FIELDS), 'reference' => (string) $customer->getKey()]);
    }
}
