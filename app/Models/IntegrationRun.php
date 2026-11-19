<?php

namespace App\Models;

use App\Domain\Integrations\Area;
use App\Exceptions\Refusal;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Throwable;

/**
 * One exchange with a linked system: a whole pull of an area, or one record
 * sent. What the settings screen shows under "last syncs", and where a failure
 * that happened at night in a queue worker can still be read the next morning.
 */
class IntegrationRun extends Model
{
    /** A complete pull: everything there was compared. */
    public const PULL = 'pull';

    /** Only what the other side said changed since the last pull. */
    public const PULL_CHANGES = 'pull_changes';

    /** One record, after a webhook named it. Not a pull of the area: it says nothing about what else changed. */
    public const NOTICE = 'notice';

    public const PUSH = 'push';

    public const RUNNING = 'running';

    public const OK = 'ok';

    public const FAILED = 'failed';

    public $timestamps = false;

    protected $fillable = [
        'integration_connection_id',
        'area',
        'direction',
        'subject_type',
        'subject_id',
        'status',
        'counts',
        'message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'area' => Area::class,
        'counts' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** What the run log calls each outcome a sync can count. */
    private const OUTCOMES = [
        'created' => 'nieuw',
        'linked' => 'gekoppeld aan een bestaande',
        'updated' => 'bijgewerkt',
        'merged' => 'samengevoegd',
        'kept_ours' => 'Lavoro-versie behouden',
        'unchanged' => 'ongewijzigd',
        'skipped' => 'overgeslagen',
        'unlinked' => 'koppeling vervallen',
        'missing' => 'daar verdwenen',
        'sent' => 'verzonden',
    ];

    public static function start(IntegrationConnection $connection, Area $area, string $direction, ?Model $subject = null): self
    {
        return self::create([
            'integration_connection_id' => $connection->id,
            'area' => $area,
            'direction' => $direction,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'status' => self::RUNNING,
            'started_at' => now(),
        ]);
    }

    /**
     * Runs $work as one logged exchange: the counts it returns on success, the
     * reason on failure -- on the run and on the connection both, so a failure
     * at night is still on the settings screen the next morning. The failure is
     * passed on afterwards.
     *
     * @param  Closure(): array<string, int>  $work
     */
    public static function track(IntegrationConnection $connection, Area $area, string $direction, ?Model $subject, Closure $work): self
    {
        $run = self::start($connection, $area, $direction, $subject);

        try {
            $counts = $work();
        } catch (Throwable $e) {
            $run->fail($e);

            throw $e;
        }

        $connection->noteSuccess();

        return $run->finish($counts);
    }

    /** @param  array<string, int>  $counts */
    public function finish(array $counts = [], ?string $message = null): self
    {
        $this->update([
            'status' => self::OK,
            'counts' => array_filter($counts),
            'message' => $message,
            'finished_at' => now(),
        ]);

        return $this;
    }

    public function fail(Throwable $e): self
    {
        $message = self::describe($e);

        $this->update(['status' => self::FAILED, 'message' => $message, 'finished_at' => now()]);
        $this->integrationConnection->noteFailure($message);

        return $this;
    }

    /**
     * A refusal says itself what is wrong. Anything else is a fault of ours:
     * named as such here, and reported by whoever lets it go on.
     */
    private static function describe(Throwable $e): string
    {
        return $e instanceof Refusal ? $e->getMessage() : 'Onverwachte fout: ' . $e->getMessage();
    }

    /** "3 nieuw, 5 bijgewerkt", for a flash message and the run log alike. */
    public function summary(): string
    {
        $parts = [];

        foreach ($this->counts ?? [] as $outcome => $count) {
            $parts[] = $count . ' ' . (self::OUTCOMES[$outcome] ?? $outcome);
        }

        return $parts === [] ? 'niets te doen' : implode(', ', $parts);
    }

    /**
     * Not connection(): every model already has a $connection, the name of its
     * database connection, and the relation would read as that string.
     */
    public function integrationConnection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeOlderThan(Builder $query, \DateTimeInterface $moment): Builder
    {
        return $query->where('started_at', '<', $moment);
    }
}
