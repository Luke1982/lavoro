<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    protected $connection = 'central';

    protected $fillable = ['key', 'name', 'price_cents', 'sort_order'];
}
