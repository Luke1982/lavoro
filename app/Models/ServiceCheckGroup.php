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
        'product_type_id',
    ];

    public function productType()
    {
        return $this->belongsTo(ProductType::class);
    }
}
