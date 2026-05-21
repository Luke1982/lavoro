<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarGrant extends Model
{
    protected $fillable = ['owner_user_id', 'viewer_user_id'];

    public function ownerUser()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function viewerUser()
    {
        return $this->belongsTo(User::class, 'viewer_user_id');
    }
}
