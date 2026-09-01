<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    /**
     * De rol van MajorLabel zelf binnen de database van een klant. Mag alles,
     * overal, langs elke policy heen.
     *
     * Alleen aan te maken vanuit het beheerpaneel. Een klant kan hem niet
     * kiezen, niet aanmaken, niet hernoemen en niet toekennen -- anders is het
     * geen scheiding maar een suggestie.
     */
    public const SUPERADMIN = 'superadmin';

    protected $fillable = [
        'name',
    ];

    /** Rollen die een klant mag zien en gebruiken: alles behalve de onze. */
    public function scopeAssignable($query)
    {
        return $query->where('name', '!=', self::SUPERADMIN);
    }

    /**
     * Users that have this role.
     */
    public function users()
    {
        return $this->morphedByMany(User::class, 'roleable', 'roleables')->withTimestamps();
    }

    /**
     * Permissions attached to this role.
     */
    public function permissions()
    {
        return $this->morphToMany(Permission::class, 'permissionable', 'permissionables')->withTimestamps();
    }
}
