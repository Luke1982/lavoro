<?php

namespace App\Models;

use App\Domain\Signals\Announcements\AnnouncementRecipientsChanged;
use App\Domain\Signals\Signals;
use App\Domain\Signals\Users\NameListChange;
use App\Models\Traits\HasActivities;
use App\Models\Traits\HasOwner;
use App\Models\Traits\RecordsHistory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Een bericht aan de eigen mensen dat pas verdwijnt als de lezer bevestigt dat
 * hij het gezien heeft. Het verschil met een melding zit in die bevestiging:
 * een melding informeert, een aankondiging vraagt een handtekening.
 *
 * De doelgroep is een momentopname. Bij het opslaan worden de ontvangers
 * uitgeschreven naar rijen, ook bij "iedereen", zodat "12 van de 20 bevestigd"
 * één telling is en wie later in dienst komt niet begint met andermans verleden.
 */
class InternalAnnouncement extends Model
{
    use HasActivities;
    use HasOwner;
    use RecordsHistory;

    /** De waarde in userables.type die een rij tot ontvangerrij maakt. */
    public const RECIPIENT_TYPE = 'announcement_recipient';

    protected $fillable = [
        'title',
        'body',
        'is_for_everyone',
        'expires_on',
    ];

    protected $casts = [
        'is_for_everyone' => 'boolean',
        'expires_on' => 'date:Y-m-d',
    ];

    protected array $activity_labels = [
        'title' => 'Titel',
        'body' => 'Bericht',
        'is_for_everyone' => 'Aan iedereen',
        'expires_on' => 'Zichtbaar tot en met',
    ];

    protected array $activity_html = [
        'body',
    ];

    /**
     * Who wrote it, taken just before deleting takes the links away: the
     * "Verwijderd" line goes on their timeline, because the announcement's own
     * is gone with it.
     *
     * @var Collection<int, User>|null
     */
    private ?Collection $authors_at_deletion = null;

    /**
     * Een morph-pivot heeft geen refererende sleutel die met het record meegaat,
     * dus die rijen blijven staan tenzij iemand ze weghaalt. Verwijderen neemt
     * dus ook de ontvangers met hun bevestigingen mee, en de tijdlijn.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $announcement) {
            $announcement->authors_at_deletion = $announcement->authors();

            $pivot_tables = ['userables' => 'userable', 'activityables' => 'activityable'];

            foreach ($pivot_tables as $table => $morph) {
                DB::table($table)
                    ->where("{$morph}_type", self::class)
                    ->where("{$morph}_id", $announcement->getKey())
                    ->delete();
            }
        });
    }

    /**
     * Making and removing an announcement is also news on its author's timeline:
     * after removal it is the only place that still says it existed.
     *
     * @return array<int, User>
     */
    public function historyTimelines(string $action): array
    {
        return match ($action) {
            'created' => $this->authors()->all(),
            'deleted' => ($this->authors_at_deletion ?? collect())->all(),
            default => [],
        };
    }

    public function historyLabel(): ?string
    {
        return 'Aankondiging "' . $this->title . '"';
    }

    /** @return Collection<int, User> */
    private function authors(): Collection
    {
        return $this->owners()->wherePivot('type', 'owner')->get();
    }

    /**
     * Ontvangers, met op het pivot het moment waarop ze bevestigden. Verwijderde
     * gebruikers vallen er vanzelf buiten: wie weg is telt niet mee in "wie moet
     * dit nog bevestigen".
     */
    public function recipients(): MorphToMany
    {
        return $this
            ->morphToMany(User::class, 'userable')
            ->withPivot('acknowledged_at')
            ->wherePivot('type', self::RECIPIENT_TYPE)
            ->withTimestamps();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where(
            fn (Builder $q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', today()->toDateString())
        );
    }

    /**
     * De oudste aankondiging die deze gebruiker nog moet bevestigen, of null.
     * Eén tegelijk: een stapel balken leest niemand, en dan is bevestigen een
     * kwestie van doorklikken geworden.
     */
    public static function openFor(User $user): ?self
    {
        return static::query()
            ->open()
            ->whereHas('recipients', fn (Builder $query) => $query
                ->whereKey($user->getKey())
                ->whereNull('userables.acknowledged_at'))
            ->oldest()
            ->first();
    }

    /**
     * Wie de aankondiging kreeg en wanneer die bevestigde, op naam gesorteerd.
     * Het pivot geeft een kale datumtekst terug, en die moet hier ISO worden:
     * "2026-08-25 14:30:00" is voor de ene browser een datum en voor de andere
     * onzin.
     *
     * @return Collection<int, array{id: int, name: string, acknowledged_at: ?string}>
     */
    public function recipientRoster(): Collection
    {
        return $this->recipients()
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'acknowledged_at' => $user->pivot->acknowledged_at
                    ? Carbon::parse($user->pivot->acknowledged_at)->toIso8601String()
                    : null,
            ]);
    }

    /**
     * Een bevestiging is een vastgelegd feit en gaat niet verloren doordat de
     * doelgroep later verandert: wie al bevestigde blijft staan, ook als hij uit
     * de lijst gehaald wordt.
     *
     * @param  array<int, int|string>  $user_ids
     */
    public function syncRecipients(array $user_ids): void
    {
        $names = fn () => $this->recipients()->withoutSuperAdmins()->pluck('users.name', 'users.id')->all();
        $before = $names();

        $wanted = array_values(array_unique(array_map('intval', $user_ids)));
        $current = $this->recipients()->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        $to_attach = array_diff($wanted, $current);
        $to_detach = array_diff($current, $wanted);

        if ($to_attach) {
            $this->recipients()->attach(
                array_fill_keys($to_attach, ['type' => self::RECIPIENT_TYPE])
            );
        }

        if ($to_detach) {
            $this->recipients()->wherePivotNull('acknowledged_at')->detach(array_values($to_detach));
        }

        $recipients = new NameListChange($before, $names());

        if (!$recipients->isEmpty()) {
            Signals::dispatch(new AnnouncementRecipientsChanged($this, $recipients));
        }
    }

    /**
     * Legt vast dat deze gebruiker bevestigde, en geeft terug of dat nieuw was.
     * Twee keer op Begrepen drukken verzet het moment niet: de eerste keer is de
     * waarheid, de tweede was een dubbele klik.
     */
    public function acknowledgeFor(User $user): bool
    {
        return $this->recipients()
            ->wherePivotNull('acknowledged_at')
            ->updateExistingPivot($user->getKey(), ['acknowledged_at' => now()]) > 0;
    }
}
