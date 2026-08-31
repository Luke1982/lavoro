<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class Reseller extends Model
{
    protected $connection = 'central';

    protected $table = 'resellers';

    protected $fillable = ['name', 'email', 'commission_percent'];
}
