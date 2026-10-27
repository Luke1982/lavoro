<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class ModuleBundle extends Model
{
    protected $connection = 'central';

    protected $fillable = ['name', 'module_keys', 'price_cents'];

    protected $casts = ['module_keys' => 'array'];
}
