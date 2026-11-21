<?php

namespace App\Models;

use App\Domain\Signals\Settings\SettingChanged;
use App\Domain\Signals\Signals;
use App\Models\Traits\HasActivities;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class GeneralSetting extends Model
{
    use HasActivities;

    /** Keys that belong on disk encrypted. */
    public const SECRET_KEYS = [
        'graph_client_secret', 'mail_smtp_password', 'mail_imap_password',
    ];

    /**
     * Bookkeeping the application keeps for itself, not a choice anyone made:
     * the storage counter moves with every upload and would bury the history of
     * the real settings.
     */
    public const UNRECORDED_KEYS = [
        'storage_used_bytes',
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
         * touches mail.
         */
        try {
            return Crypt::decryptString((string) $row->value);
        } catch (DecryptException) {
            return $default;
        }
    }

    /**
     * The one way a setting is written, so it is also the one place that says
     * so. A secret is compared after decrypting: encrypting the same password
     * twice gives two different texts, and that is not a change.
     */
    public static function set(string $key, mixed $value): void
    {
        $value = (string) $value;
        $recorded = !in_array($key, static::UNRECORDED_KEYS, true);
        $previous = $recorded ? static::get($key) : null;

        $stored = in_array($key, static::SECRET_KEYS, true) && $value !== ''
            ? Crypt::encryptString($value)
            : $value;

        $setting = static::updateOrCreate(['key' => $key], ['value' => $stored]);

        if (!$recorded || (string) $previous === $value) {
            return;
        }

        Signals::dispatch(SettingChanged::withholdsValueOf($key)
            ? new SettingChanged($setting, null, null, cleared: $value === '')
            : new SettingChanged($setting, $previous === null ? null : (string) $previous, $value));
    }
}
