<?php

namespace App\Models;

use App\Models\ServiceOrderTaskInstance;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceOrderTask extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'description'];

    public function instances()
    {
        return $this->hasMany(ServiceOrderTaskInstance::class);
    }
}
