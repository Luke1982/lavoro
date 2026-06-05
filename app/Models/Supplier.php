<?php

namespace App\Models;

use App\Models\Traits\HasCustomFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;
    use HasCustomFields;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'mobile',
        'website',
        'contact_person',
        'address',
        'postal_code',
        'city',
        'country',
        'iban',
        'vat_number',
        'kvk_number',
    ];

    public function products()
    {
        return $this->morphedByMany(Product::class, 'suppliable')
            ->withPivot('article_number', 'is_preferred')
            ->withTimestamps();
    }

    public function materials()
    {
        return $this->morphedByMany(Material::class, 'suppliable')
            ->withPivot('article_number', 'is_preferred')
            ->withTimestamps();
    }
}
