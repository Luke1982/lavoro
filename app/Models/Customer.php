<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerFactory> */
    use HasFactory;

    protected $fillable = [
        'snelstart_id',
        'name',
        'email',
        'invoice_email',
        'quotes_email',
        'phone',
        'mobile',
        'website',
        'address',
        'postal_code',
        'city',
        'country',
        'postal_address',
        'postal_postal_code',
        'postal_city',
        'postal_country',
        'iban',
        'vat_number',
        'chamber_of_commerce_number',
        'contactname',
        'location_code',
    ];
}
