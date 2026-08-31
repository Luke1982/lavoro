<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class PricingSetting extends Model
{
    protected $connection = 'central';

    protected $fillable = ['key', 'value'];

    public static function value(string $key, int $default = 0): int
    {
        $row = static::on('central')->where('key', $key)->first();

        return $row ? (int) $row->value : $default;
    }
}
