<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $connection = 'central';

    protected $fillable = ['key', 'name', 'field_seats', 'office_seats', 'price_cents', 'extra_field_cents', 'extra_office_cents', 'sort_order'];
}
