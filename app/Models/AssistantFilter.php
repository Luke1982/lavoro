<?php

namespace App\Models;

use App\Domain\Assistant\Reading\ClosedToThisPerson;
use App\Domain\Assistant\Reading\Conditions;
use App\Domain\Assistant\Reading\RecordKinds;
use App\Exceptions\Refusal;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A one-time filter on an overview page, handed out by the assistant as a link.
 *
 * "Welke werkbonnen staan langer dan een maand open?" is answered with a count
 * and "/serviceorders?filter=<uuid>", never with forty rows in the chat. The
 * overview applies its own rules as always and then this narrows it further, so
 * a filter can only take away from what the page already shows. The conditions
 * are compiled again for whoever opens the link, with their permissions: a
 * colleague who may not read a field the filter names sees nothing rather than
 * a list sorted by something hidden from them.
 *
 * Kept as long as the assistant's conversations are (assistant.keep_months);
 * PruneAssistantFiltersJob removes the rest every night. A link to one that is
 * gone, or to one for another list, shows nothing rather than everything, and
 * the bar says why.
 *
 * @property string $uuid
 * @property int|null $user_id
 * @property class-string<Model> $kind
 * @property array $conditions
 * @property string $summary
 */
class AssistantFilter extends Model
{
    private const DEFAULT_KEEP_MONTHS = 6;

    private const ATTRIBUTE = 'assistant_filter';

    /** What applying the filter came to, so the bar above the list does not compile it a second time. */
    private const OUTCOME = 'assistant_filter_outcome';

    private const APPLIED = 'applied';

    private const REFUSED = 'refused';

    /** Refused because a condition no longer fits the records: a field dropped or renamed since. */
    private const STALE = 'stale';

    private const MISSING = 'missing';

    private const ELSEWHERE = 'elsewhere';

    /** Query parameters that order or page a list without narrowing it. */
    private const NOT_NARROWING = ['filter', 'page', 'sort', 'direction', 'perPage'];

    /** What a list's own filter says when it means "every one", so it does not count as narrowing. */
    private const EVERYTHING = ['all'];

    protected $fillable = [
        'uuid',
        'user_id',
        'kind',
        'conditions',
        'summary',
    ];

    protected $casts = [
        'conditions' => 'array',
    ];

    /** @param  class-string<Model>  $class */
    public static function record(User $user, string $class, array $conditions, string $summary): self
    {
        return self::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'kind' => $class,
            'conditions' => $conditions,
            'summary' => Str::limit($summary, 990),
        ]);
    }

    public function url(): string
    {
        return RecordKinds::overview($this->kind) . '?filter=' . $this->uuid;
    }

    /** Whether the request asks for a filter at all, found or not. */
    public static function isAsked(Request $request): bool
    {
        return $request->query->has('filter');
    }

    /**
     * For an overview controller, in one line: `$query->tap(AssistantFilter::from($request))`.
     * The kind is the query's own model: a filter for werkbonnen on the customer
     * list, like a filter that no longer exists, empties the list.
     */
    public static function from(Request $request): Closure
    {
        return fn (Builder $query) => self::applyTo($query, $request);
    }

    public static function applyTo(Builder $query, Request $request): Builder
    {
        if (!self::isAsked($request)) {
            return $query;
        }

        $filter = self::named($request);
        $user = $request->user();

        $outcome = match (true) {
            $filter === null || $user === null => self::MISSING,
            $filter->kind !== $query->getModel()::class => self::ELSEWHERE,
            in_array($request->attributes->get(self::OUTCOME), [self::REFUSED, self::STALE], true) => $request->attributes->get(self::OUTCOME),
            default => self::compile($query, $filter, $user),
        };

        $request->attributes->set(self::OUTCOME, $outcome);

        return $outcome === self::APPLIED ? $query : $query->whereRaw('1 = 0');
    }

    /**
     * What the main layout shows above a filtered overview, or null. Only on an
     * overview that takes a filter, and for the filter's own kind only for
     * somebody who may open it.
     *
     * The summary is described again for whoever opens the link, so it only
     * names records they may see. When a condition is closed to them, the
     * summary is left out altogether: it would name the field they may not read
     * and the value it was compared with. A condition that no longer fits the
     * records (a field dropped or renamed since) is said to be out of date, not
     * blamed on their rights.
     *
     * @return array{summary: string|null, refused: string|null, narrowed: bool}|null
     */
    public static function bannerFor(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null || !tenancy()->initialized || !self::isAsked($request) || !RecordKinds::isOverview($request->path())) {
            return null;
        }

        $filter = self::named($request);

        if ($filter === null) {
            return self::banner(null, 'Dit filter bestaat niet meer — vraag het de assistent opnieuw.');
        }

        if (RecordKinds::overview($filter->kind) !== '/' . trim($request->path(), '/')) {
            return self::banner(null, 'Dit filter hoort bij een andere lijst — vraag het de assistent opnieuw.');
        }

        if (!RecordKinds::mayList($filter->kind, $user)) {
            return null;
        }

        $conditions = new Conditions($filter->kind, $user);
        $outcome = $request->attributes->get(self::OUTCOME) ?? self::compile($filter->kind::query(), $filter, $user);

        if ($outcome === self::STALE) {
            return self::banner(null, 'Dit filter klopt niet meer — vraag het de assistent opnieuw.');
        }

        if ($outcome !== self::APPLIED) {
            return self::banner(null, 'Dit filter kun jij niet gebruiken: het gaat over gegevens die jij niet mag zien, dus de lijst blijft leeg.');
        }

        return self::banner($conditions->describe($filter->conditions), null, self::narrowedFurther($request));
    }

    /** @return array{summary: string|null, refused: string|null, narrowed: bool} */
    private static function banner(?string $summary, ?string $refused, bool $narrowed = false): array
    {
        return ['summary' => $summary, 'refused' => $refused, 'narrowed' => $narrowed];
    }

    /** The conditions onto the query, compiled with this person's rights. */
    private static function compile(Builder $query, self $filter, User $user): string
    {
        try {
            (new Conditions($filter->kind, $user))->apply($query, $filter->conditions);

            return self::APPLIED;
        } catch (ClosedToThisPerson) {
            return self::REFUSED;
        } catch (Refusal) {
            return self::STALE;
        }
    }

    /** Whether the person searched or filtered the list further by hand, on top of the filter. */
    private static function narrowedFurther(Request $request): bool
    {
        foreach ($request->query() as $key => $value) {
            if (!in_array($key, self::NOT_NARROWING, true) && self::narrows($value)) {
                return true;
            }
        }

        return false;
    }

    private static function narrows(mixed $value): bool
    {
        if (is_array($value)) {
            return array_filter($value, fn ($entry) => self::narrows($entry)) !== [];
        }

        return filled($value) && !in_array($value, self::EVERYTHING, true);
    }

    /** The filter the request names in ?filter=, looked up once per request. */
    private static function named(Request $request): ?self
    {
        if ($request->attributes->has(self::ATTRIBUTE)) {
            return $request->attributes->get(self::ATTRIBUTE);
        }

        $uuid = $request->query('filter');

        $filter = is_string($uuid) && Str::isUuid($uuid)
            ? self::where('uuid', $uuid)->first()
            : null;

        $request->attributes->set(self::ATTRIBUTE, $filter);

        return $filter;
    }

    /** Filters go when the conversations they came from would: after assistant.keep_months, or the months assistant:prune was given. */
    public static function prune(?int $months = null): int
    {
        return self::olderThan($months ?? self::keepMonths())->delete();
    }

    /** @return Builder<self> */
    public static function olderThan(int $months): Builder
    {
        return self::where('created_at', '<', now()->subMonths($months));
    }

    /**
     * How many months the assistant keeps its conversations, and these with them:
     * assistant.keep_months when that is a whole number above zero, six when it is
     * empty or anything else.
     */
    public static function keepMonths(): int
    {
        return self::monthsFrom(config('assistant.keep_months')) ?? self::DEFAULT_KEEP_MONTHS;
    }

    /** A whole number of months above zero, or null for anything else. */
    public static function monthsFrom(mixed $value): ?int
    {
        $months = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $months === false ? null : $months;
    }
}
