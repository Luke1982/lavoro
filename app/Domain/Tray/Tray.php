<?php

namespace App\Domain\Tray;

use App\Domain\Tray\Paste\Paste;
use App\Domain\Tray\Paste\PasteHandler;
use App\Domain\Tray\Paste\TicketsOntoAsset;
use App\Domain\Tray\Paste\TicketsOntoPlanner;
use App\Domain\Tray\Paste\TicketsOntoProject;
use App\Domain\Tray\Paste\TicketsOntoServiceOrder;
use App\Domain\Tray\Paste\UndoablePasteHandler;
use App\Exceptions\Refusal;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TrayItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A person's bakje: what is on it, putting things on it, and pasting them off.
 *
 * Every paste runs inside TrayContext, so whatever it changes lands on the
 * timeline marked as done via the bakje, by the same actions the screens use.
 */
class Tray
{
    /** @var array<string, class-string<PasteHandler>> What can be dropped where, as "kind:target". */
    private const HANDLERS = [
        'ticket:planner' => TicketsOntoPlanner::class,
        'ticket:serviceorder' => TicketsOntoServiceOrder::class,
        'ticket:asset' => TicketsOntoAsset::class,
        'ticket:project' => TicketsOntoProject::class,
    ];

    /** @var array<int, ?string> */
    private array $customer_names = [];

    /** @return array<int, string> Every place something can be dropped. */
    public static function targets(): array
    {
        return array_values(array_unique(array_map(
            fn (string $key) => Str::after($key, ':'),
            array_keys(self::HANDLERS),
        )));
    }

    /** @return array<int, string> The pastes that can be taken back, as "kind:target". */
    public static function undoable(): array
    {
        return array_keys(array_filter(
            self::HANDLERS,
            fn (string $handler) => is_subclass_of($handler, UndoablePasteHandler::class),
        ));
    }

    /**
     * What is on the bakje, in the order it was put there. Anything that has gone,
     * or that this person can no longer see, falls off on the way.
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(User $user): array
    {
        $items = $user->trayItems()->orderBy('position')->orderBy('id')->get();
        $described = [];

        foreach ($items->groupBy('trayable_type') as $type => $group) {
            $kind = TrayKinds::kindFor($type);
            $records = $kind === null
                ? collect()
                : TrayKinds::visible($kind, $group->pluck('trayable_id')->all(), $user)->keyBy('id');

            foreach ($group as $item) {
                if ($record = $records->get($item->trayable_id)) {
                    $described[$item->id] = $this->describe($kind, $item, $record);
                }
            }
        }

        $gone = $items->pluck('id')->diff(array_keys($described));

        if ($gone->isNotEmpty()) {
            TrayItem::whereIn('id', $gone)->delete();
        }

        return array_values($described);
    }

    /**
     * Puts records on the bakje. All of them must be ones this person can open.
     *
     * @param  array<int, int>  $ids
     */
    public function place(User $user, string $kind, array $ids): void
    {
        $ids = array_unique(array_map('intval', $ids));
        $records = TrayKinds::visible($kind, $ids, $user);

        if ($records->count() !== count($ids)) {
            throw new Refusal('Een deel hiervan kun je niet openen, dus het kan niet op je bakje.');
        }

        $this->put($user, $kind, $records);
    }

    /**
     * Pastes these bakje items on a target. Returns the question to put to the
     * person first, or what was done. An answer only counts when it is one of
     * the choices the question offers now: the screen may have been open a while.
     *
     * @param  array<int, int>  $item_ids
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    public function paste(User $user, array $item_ids, array $target, ?string $answer, bool $remove): array
    {
        $items = $user->trayItems()->whereIn('id', $item_ids)->get();

        if ($items->count() !== count(array_unique($item_ids))) {
            throw new Refusal('Dit ligt niet meer op je bakje.');
        }

        $kinds = $items->map(fn (TrayItem $item) => TrayKinds::kindFor($item->trayable_type))->unique();

        if ($kinds->count() !== 1 || $kinds->first() === null) {
            throw new Refusal('Plak één soort tegelijk.');
        }

        $kind = $kinds->first();
        $records = TrayKinds::visible($kind, $items->pluck('trayable_id')->all(), $user);

        if ($records->count() !== $items->count()) {
            throw new Refusal('Een deel hiervan kun je niet meer openen.');
        }

        $key = $kind . ':' . $target['type'];
        $handler = $this->handler($key, $kind);
        $question = $handler->question(new Paste($user, $records, $target));

        if ($question !== null && $answer === null) {
            return ['question' => $question];
        }

        if ($question !== null && !$question->offers($answer)) {
            throw new Refusal('Die keuze is er niet meer. Sleep het opnieuw.');
        }

        $paste = new Paste($user, $records, $target, $answer);
        $result = app(TrayContext::class)->run(fn () => DB::transaction(fn () => $handler->perform($paste)));

        if ($remove) {
            $items->reject->pinned->each->delete();
        }

        return [
            'done' => true,
            'message' => $result->message,
            'created' => $result->created,
            'undo' => $result->undo === null ? null : [
                ...$result->undo,
                'handler' => $key,
                'kind' => $kind,
                'record_ids' => $records->modelKeys(),
            ],
            'items' => $this->items($user),
        ];
    }

    /**
     * Takes a paste back and puts what was pasted back on the bakje, as far as
     * this person can still open it.
     *
     * @param  array<string, mixed>  $undo
     * @return array<string, mixed>
     */
    public function undo(User $user, array $undo): array
    {
        $handler = app(self::HANDLERS[$undo['handler']]);

        if (!$handler instanceof UndoablePasteHandler) {
            throw new Refusal('Dit kun je niet ongedaan maken.');
        }

        $message = app(TrayContext::class)->run(fn () => DB::transaction(fn () => $handler->undo($user, $undo)));

        $this->put($user, $undo['kind'], TrayKinds::visible($undo['kind'], $undo['record_ids'], $user));

        return ['message' => $message, 'items' => $this->items($user)];
    }

    /**
     * Two clicks that both arrive before either is saved both find the record
     * missing; createOrFirst lets the second take the row the first just wrote.
     */
    private function put(User $user, string $kind, Collection $records): void
    {
        $type = TrayKinds::classFor($kind);
        $position = (int) $user->trayItems()->max('position');

        foreach ($records as $record) {
            TrayItem::createOrFirst(
                ['user_id' => $user->id, 'trayable_type' => $type, 'trayable_id' => $record->getKey()],
                ['position' => ++$position],
            );
        }
    }

    private function handler(string $key, string $kind): PasteHandler
    {
        if (!isset(self::HANDLERS[$key])) {
            throw new Refusal('Een ' . TrayKinds::counted($kind, 1) . ' kun je hier niet plakken.');
        }

        return app(self::HANDLERS[$key]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(string $kind, TrayItem $item, Model $record): array
    {
        return [
            'id' => $item->id,
            'kind' => $kind,
            'record_id' => $record->getKey(),
            'pinned' => $item->pinned,
            ...match ($kind) {
                'ticket' => $this->describeTicket($record),
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeTicket(Ticket $ticket): array
    {
        $customer_id = TicketFacts::customerId($ticket);

        return [
            'title' => TicketFacts::title($ticket),
            'subtitle' => TicketFacts::machine($ticket),
            'customer_id' => $customer_id,
            'customer_name' => $customer_id === null ? null : $this->customerName($customer_id),
            'service_order_id' => $ticket->service_order_id,
            'service_order_number' => $ticket->serviceOrder?->number,
            'url' => '/tickets/' . $ticket->id,
        ];
    }

    private function customerName(int $customer_id): ?string
    {
        return $this->customer_names[$customer_id] ??= Customer::whereKey($customer_id)->value('name');
    }
}
