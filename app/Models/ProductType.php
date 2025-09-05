<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductType extends Model
{
    /** @use HasFactory<\Database\Factories\ProductTypeFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'typical_certificate_days',
    ];

    public function serviceChecks()
    {
        return $this->morphedByMany(ServiceCheck::class, 'producttypeable');
    }

    public function serviceCheckGroups()
    {
        return $this->morphedByMany(ServiceCheckGroup::class, 'producttypeable');
    }
}
