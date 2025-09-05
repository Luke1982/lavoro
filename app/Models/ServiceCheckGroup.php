<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceCheckGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'order',
    ];

    public function productTypes()
    {
        return $this->morphToMany(ProductType::class, 'producttypeable');
    }
}
