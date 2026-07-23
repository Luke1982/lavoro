<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property float $quantity
 * @property string $description
 * @property bool $unforseen
 */
class FreeformMaterial extends Model
{
    protected $fillable = [
        'quantity',
        'description',
        'unforseen',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unforseen' => 'boolean',
    ];

    public function freeformmateriable()
    {
        return $this->morphTo();
    }
}
