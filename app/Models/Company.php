<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'address_line1',
        'address_line2',
        'postal_code',
        'city',
        'country',
        'logo_path',
        'is_main'
    ];

    protected $casts = [
        'is_main' => 'boolean'
    ];

    protected static function booted(): void
    {
        static::saving(function (Company $company) {
            if ($company->is_main) {
                DB::transaction(function () use ($company) {
                    Company::where('is_main', true)
                        ->where('id', '!=', $company->id)
                        ->update(['is_main' => false]);
                });
            }
        });
    }
}
