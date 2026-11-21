<?php

namespace App\Models;

use App\Enums\TicketStatusses;
use App\Models\Traits\HasActivities;
use App\Models\Traits\HasCustomFields;
use App\Models\Traits\RecordsHistory;
use App\Models\Traits\RemarkableTrait;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    use HasActivities;
    use HasCustomFields;

    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    use RecordsHistory;
    use RemarkableTrait;

    protected array $activity_labels = [
        'status_code' => 'Statuscode',
        'created_by_id' => 'Aangemaakt door',
        'asset_id' => 'Machine',
        'subject' => 'Onderwerp',
        'description' => 'Omschrijving',
        'status' => 'Status',
        'priority' => 'Prioriteit',
        'closed_on' => 'Gesloten op',
        'closed_by_id' => 'Gesloten door',
        'service_order_id' => 'Werkbon',
    ];

    /**
     * Reported by named signals of their own, so the automatic trail must not
     * repeat them.
     */
    protected array $activity_ignore = [
        'status',
        'priority',
    ];

    protected array $activity_relations = [
        'asset_id' => ['asset', 'serial_number'],
        'service_order_id' => ['serviceOrder', 'number'],
        'created_by_id' => ['createdBy', 'name'],
        'closed_by_id' => ['closedBy', 'name'],
    ];

    /**
     * Why the status is about to change when the app moves it by itself. Not a
     * column: the observer reads it once, as the change is announced.
     */
    public ?string $status_reason = null;

    protected $fillable = [
        'asset_id',
        'subject',
        'description',
        'status',
        'priority',
        'status_code',
        'closed_on',
        'closed_by_id',
        'created_by_id',
        'service_order_id',
    ];

    /**
     * A storing is visible when the werkbon or the machine it hangs off is.
     *
     * Storingen have no readership of their own: someone is put on a werkbon or
     * given a machine, and the storingen on it come with that. Inventing a
     * separate rule here would let history and search disagree with what opening
     * the record actually shows.
     *
     * Dat leunen op ServiceOrder::visibleTo is precies waarom een monteur die
     * ingepland staat op een werkbon ook de storingen erop ziet: die scope kent
     * naast "staat als uitvoerder op de werkbon" ook "voert een afspraak op de
     * werkbon uit". Wie hier een eigen regel zou schrijven, moest die tweede weg
     * opnieuw bedenken — en zou hem de volgende keer vergeten.
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isAdmin() || $user->hasPermission('ticket.read')) {
            return $query;
        }

        return $query->where(fn ($q) => $q
            ->whereIn('service_order_id', ServiceOrder::visibleTo($user)->select('service_orders.id'))
            ->orWhereIn('asset_id', Asset::visibleTo($user)->select('assets.id')));
    }

    /**
     * Moves the status on the app's own initiative, saying why on the timeline,
     * since nobody's hand is there to explain it.
     */
    public function moveToStatus(TicketStatusses $status, string $reason): void
    {
        $this->status_reason = $reason;

        try {
            $this->update(['status' => $status->value]);
        } finally {
            $this->status_reason = null;
        }
    }

    /**
     * Its creation and removal are news on the customer's timeline, and its
     * removal on the werkbon it was on too, where it was being worked on; every
     * edit after that stays on its own.
     */
    public function historyTimelines(string $action): array
    {
        return match ($action) {
            'created' => [$this->asset?->resolvedCustomer()],
            'deleted' => [$this->asset?->resolvedCustomer(), $this->serviceOrder],
            default => [],
        };
    }

    public function historyLabel(): ?string
    {
        return 'Storing ' . ($this->subject ?: '#' . $this->id);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id')->withTrashed();
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->withPivot(['main'])
            ->withTimestamps();
    }

    public function documents()
    {
        return $this->morphToMany(Document::class, 'documentable')
            ->withTimestamps();
    }
}
