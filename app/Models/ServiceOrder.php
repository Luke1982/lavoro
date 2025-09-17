<?php

namespace App\Models;

use App\Models\Traits\RemarkableTrait;
use App\Models\Activity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\HasOwner;
use App\Models\Traits\HasExecutingUsers;

class ServiceOrder extends Model
{
    /** @use HasFactory<\Database\Factories\ServiceOrderFactory> */
    use HasFactory;
    use RemarkableTrait;
    use HasOwner;
    use HasExecutingUsers;

    protected $fillable = [
        'description',
        'customer_id',
        'closed_on',
        'signed_by',
        'signature_base64',
    'sent_to_administration',
    'sent_to_customer',
    ];

    protected $casts = [
    'sent_to_administration' => 'boolean',
    'sent_to_customer' => 'boolean',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function serviceJobs()
    {
        return $this->hasMany(ServiceJob::class);
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function materials()
    {
        return $this->morphToMany(
            Material::class,
            'materiable',
        )->withPivot(
            'quantity',
            'material_role_id',
            'id'
        )->withTimestamps();
    }

    public function events()
    {
        return $this->morphToMany(Event::class, 'eventable');
    }

    public function activities()
    {
        return $this->morphToMany(Activity::class, 'activityable')->withTimestamps();
    }

    public function logActivity(
        string $description,
        ?\DateTimeInterface $occurredAt = null,
        ?string $category = null
    ): Activity {
        // Basic inference if no category explicitly provided
        if (!$category) {
            $lower = mb_strtolower($description);
            $category = match (true) {
                str_contains($lower, 'materiaal toegevoegd') => 'material',
                str_contains($lower, 'materiaal verwijderd') => 'material',
                str_contains($lower, 'materiaal hoeveelheid') => 'material',
                str_contains($lower, 'ticket gekoppeld') => 'ticket',
                str_contains($lower, 'ticket losgekoppeld') => 'ticket',
                str_contains($lower, 'werkbon per e-mail') => 'email',
                str_contains($lower, 'keuring per e-mail') => 'email',
                (
                    str_contains($lower, 'e-mail')
                    && (
                        str_contains($lower, 'verzonden')
                        || str_contains($lower, 'verstuurd')
                    )
                ) => 'email',
                str_contains($lower, 'status') => 'status',
                str_contains($lower, 'keuring toegevoegd') => 'created',
                default => 'other',
            };
        }

        $activity = Activity::create([
            'description' => $description,
            'category' => $category,
        ]);
        $this->activities()->attach($activity->id);
        return $activity;
    }
}
