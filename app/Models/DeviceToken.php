<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceToken extends Model
{
    /** Where the session remembers the token it handed in, so logging out can delete it. */
    public const SESSION_KEY = 'device_token';

    protected $fillable = ['user_id', 'token', 'platform'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
