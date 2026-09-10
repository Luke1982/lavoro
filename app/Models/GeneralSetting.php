<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class GeneralSetting extends Model
{
    /** Keys that belong on disk encrypted. */
    public const SECRET_KEYS = [
        'graph_client_secret', 'snelstart_client_key', 'snelstart_subscription_key',
        'mail_smtp_password',
    ];

    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::where('key', $key)->first();

        if (!$row) {
            return $default;
        }

        if (!in_array($key, static::SECRET_KEYS, true)) {
            return $row->value;
        }

        /**
         * After switching APP_KEY nothing can be deciphered any more. Then
         * return the default and do not blow up: that reads as "not set yet"
         * and produces a form instead of a white page on every place that
         * touches mail or SnelStart.
         */
        try {
            return Crypt::decryptString((string) $row->value);
        } catch (DecryptException) {
            return $default;
        }
    }

    public static function set(string $key, mixed $value): void
    {
        $value = (string) $value;

        if (in_array($key, static::SECRET_KEYS, true) && $value !== '') {
            $value = Crypt::encryptString($value);
        }

        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
