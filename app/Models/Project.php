<?php

namespace App\Models;

use App\Models\Traits\RecordsHistory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use RecordsHistory;

    protected array $activity_labels = [
        'title' => 'Titel',
        'description' => 'Omschrijving',
        'customer_id' => 'Klant',
        'project_manager_id' => 'Projectleider',
        'status' => 'Status',
        'start_date' => 'Startdatum',
        'end_date' => 'Einddatum',
        'location' => 'Locatie',
    ];

    protected array $activity_relations = [
        'customer_id' => ['customer', 'name'],
    ];

    protected array $activity_ignore = [
        'financial_notes_updated_at',
        'financial_notes_updated_by',
    ];

    protected $fillable = [
        'title',
        'description',
        'location',
        'start_date',
        'end_date',
        'customer_id',
        'project_manager_id',
        'status',
        'financial_notes',
        'financial_notes_updated_at',
        'financial_notes_updated_by',
    ];

    protected function casts(): array
    {
        return [
            'financial_notes' => 'array',
            'financial_notes_updated_at' => 'datetime',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function financialNotesUpdatedBy()
    {
        return $this->belongsTo(User::class, 'financial_notes_updated_by');
    }

    public function projectManager()
    {
        return $this->belongsTo(User::class, 'project_manager_id');
    }

    public function milestones()
    {
        return $this->hasMany(ProjectMilestone::class)->orderBy('projected_date');
    }

    public function serviceOrders()
    {
        return $this->hasMany(ServiceOrder::class);
    }

    public function documents()
    {
        return $this->morphToMany(Document::class, 'documentable')->withTimestamps();
    }

    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->withPivot(['main'])
            ->withTimestamps();
    }
}
