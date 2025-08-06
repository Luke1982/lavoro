<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaterialRole extends Model
{
    /** @use HasFactory<\Database\Factories\MaterialRoleFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
    ];
}
