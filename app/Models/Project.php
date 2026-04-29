<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $fillable = [
        'title',
        'description',
        'start_date',
        'end_date',
        'customer_id',
        'project_manager_id',
        'status',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
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
}
