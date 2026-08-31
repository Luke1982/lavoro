<?php

namespace App\Models\Central;

use Illuminate\Foundation\Auth\User as Authenticatable;

class LandlordUser extends Authenticatable
{
    protected $connection = 'central';

    protected $table = 'landlord_users';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}
