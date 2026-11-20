<?php

namespace App\Models;

use App\Models\Traits\KeepsPosition;
use Illuminate\Database\Eloquent\Model;

/**
 * A unit a quote line can be offered in, such as "uur" or "m²". A line keeps
 * the unit as text, so renaming or removing one here leaves the quotes made
 * with it as they are.
 */
class QuoteUnit extends Model
{
    use KeepsPosition;

    protected $fillable = ['name', 'position'];
}
