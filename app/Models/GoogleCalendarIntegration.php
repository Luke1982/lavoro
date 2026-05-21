<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class GoogleCalendarIntegration extends Model
{
    protected $fillable = [
        'user_id',
        'google_account_email',
        'google_account_sub',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
        'backfill_total',
        'backfill_done',
        'connected_at',
        'last_error',
        'disabled_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'disabled_at' => 'datetime',
        'scopes' => 'array',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function syncedCalendars()
    {
        return $this->hasMany(GoogleSyncedCalendar::class);
    }

    public function setAccessTokenAttribute(?string $value): void
    {
        $this->attributes['access_token'] = $value === null ? null : Crypt::encryptString($value);
    }

    public function getAccessTokenAttribute(?string $value): ?string
    {
        return $value === null ? null : Crypt::decryptString($value);
    }

    public function setRefreshTokenAttribute(?string $value): void
    {
        $this->attributes['refresh_token'] = $value === null ? null : Crypt::encryptString($value);
    }

    public function getRefreshTokenAttribute(?string $value): ?string
    {
        return $value === null ? null : Crypt::decryptString($value);
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }
}
