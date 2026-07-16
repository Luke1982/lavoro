<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'title',
        'location_code',
        'address',
        'postal_code',
        'city',
        'country',
        'lat',
        'lon',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }

    public function serviceOrders()
    {
        return $this->hasMany(ServiceOrder::class);
    }

    public function events()
    {
        return $this->hasMany(Event::class);
    }

    public function addressLine(): string
    {
        return collect([$this->address, trim($this->postal_code . ' ' . $this->city)])
            ->filter(fn ($part) => $part !== null && $part !== '')
            ->implode(', ');
    }
}
