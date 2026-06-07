<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationPing extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'lat', 'lng', 'accuracy', 'speed', 'heading', 'recorded_at'];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'accuracy' => 'float',
            'speed' => 'float',
            'heading' => 'float',
            'recorded_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
