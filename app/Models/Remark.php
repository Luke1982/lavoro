<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Remark extends Model
{
    protected $fillable = [
        'user_id',
        'author_name',
        'content',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Wie dit gezegd heeft, of het nu een collega was of een klant zonder account.
     * De naam van een klant is overgeschreven en geen verwijzing: die mag later
     * niet meeveranderen met wat er toen gezegd is.
     *
     * Met opzet niet in $appends: dat zou de gebruiker per opmerking apart ophalen
     * op elk scherm waar opmerkingen staan. Het scherm leest user?.name en valt
     * daarna terug op author_name, precies zoals hier.
     */
    public function authorLabel(): string
    {
        return $this->user?->name ?? ($this->author_name ?: 'Onbekend');
    }

    public function isFromCustomer(): bool
    {
        return $this->user_id === null;
    }
}
