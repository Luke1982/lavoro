<?php

namespace App\Models;

use App\Support\AddressFormatter;
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

    /**
     * Locaties waar de zoekterm ergens in past.
     *
     * Alles wat een locatie aanwijst telt mee, het hele adres inbegrepen: een
     * locatie wordt net zo vaak op de straat of de postcode gezocht als op de
     * naam. De kolommen staan hier één keer, zodat elke zoekingang dezelfde
     * locatie vindt.
     *
     * @param  string  $like  Patroon uit SearchTerm::like(), dus met de jokertekens er al uit.
     */
    public function scopeMatchesText($query, string $like)
    {
        return $query->where(fn ($q) => $q
            ->where('title', 'like', $like)
            ->orWhere('location_code', 'like', $like)
            ->orWhere('address', 'like', $like)
            ->orWhere('postal_code', 'like', $like)
            ->orWhere('city', 'like', $like)
            ->orWhere('country', 'like', $like));
    }

    public function addressLine(): string
    {
        return AddressFormatter::format($this->address, $this->postal_code, $this->city) ?? '';
    }
}
