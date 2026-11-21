<?php

namespace App\Models;

use App\Models\Traits\HasActivities;
use App\Models\Traits\RecordsHistory;
use Database\Factories\EventTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventType extends Model
{
    use HasActivities;

    /** @use HasFactory<EventTypeFactory> */
    use HasFactory;

    use RecordsHistory;

    protected $fillable = [
        'name',
        'description',
        'color',
    ];

    protected array $activity_labels = [
        'name' => 'Naam',
        'description' => 'Omschrijving',
        'color' => 'Kleur',
    ];
}
