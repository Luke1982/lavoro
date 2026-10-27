<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class IssuerSetting extends Model
{
    protected $connection = 'central';

    protected $table = 'issuer_settings';

    protected $fillable = ['key', 'value'];

    public static function all_values(): array
    {
        return static::on('central')->pluck('value', 'key')->all();
    }

    public static function value(string $key, string $default = ''): string
    {
        return (string) (static::on('central')->where('key', $key)->value('value') ?: $default);
    }
}
