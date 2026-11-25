<?php

namespace App\Models;

use App\Models\Traits\RemarkableTrait;
use Database\Factories\ServiceCheckInstanceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceCheckInstance extends Model
{
    /** @use HasFactory<ServiceCheckInstanceFactory> */
    use HasFactory;

    use RemarkableTrait;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'service_check_id',
        'service_job_id',
        'service_check_value_id',
        'description',
        'switch_state',
    ];

    protected $casts = [
        'switch_state' => 'boolean',
    ];

    /**
     * The service check associated with the instance.
     */
    public function serviceCheck()
    {
        return $this->belongsTo(ServiceCheck::class);
    }

    /**
     * The service job associated with the instance.
     */
    public function serviceJob()
    {
        return $this->belongsTo(ServiceJob::class);
    }

    /** The keurpunten of the keuringen this person may read, which follow their werkbon. */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return $query->whereIn($query->qualifyColumn('service_job_id'), ServiceJob::visibleTo($user)->select('service_jobs.id'));
    }

    /**
     * The service check value associated with the instance.
     */
    public function values()
    {
        return $this->belongsToMany(ServiceCheckValue::class, 'check_instance_service_value')
            ->withTimestamps();
    }

    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->withPivot(['main']);
    }

    /**
     * What this checkpoint says right now, per answer field, as the raw value and the
     * readable one: the switch as Ja/Nee, the chosen options by their wording. Taken
     * before and after a save, so ServiceCheckResultChanged can tell what moved.
     *
     * @return array<string, array{value: ?string, label: ?string}>
     */
    public function resultSnapshot(): array
    {
        $values = $this->values()->orderBy('service_check_values.id')->get();
        $description = trim((string) $this->description);

        return [
            'switch_state' => [
                'value' => $this->switch_state === null ? null : ($this->switch_state ? '1' : '0'),
                'label' => $this->switch_state === null ? null : ($this->switch_state ? 'Ja' : 'Nee'),
            ],
            'description' => [
                'value' => $description === '' ? null : $description,
                'label' => $description === '' ? null : $description,
            ],
            'values' => [
                'value' => $values->isEmpty() ? null : $values->pluck('id')->implode(','),
                'label' => $values->isEmpty() ? null : $values->pluck('value')->implode(', '),
            ],
        ];
    }
}
