<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One thing a mechanic ticks off ("Rooster schoongemaakt"), kept in a library so
 * the same check can sit on many task templates and checklists. Whether it is
 * required is decided by each link, not here.
 */
class ServiceOrderTaskCheck extends Model
{
    protected $fillable = ['title'];
}
