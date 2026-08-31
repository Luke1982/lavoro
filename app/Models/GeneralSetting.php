<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class GeneralSetting extends Model
{
    /** Sleutels die versleuteld op schijf horen te staan. */
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
         * Na het wisselen van APP_KEY is niets meer te ontcijferen. Dan de
         * standaard teruggeven en niet klappen: dat leest als "nog niet
         * ingesteld" en levert een invulscherm op in plaats van een witte
         * pagina op elke plek die mail of SnelStart aanraakt.
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
