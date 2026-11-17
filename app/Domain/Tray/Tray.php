<?php

namespace App\Domain\Tray;

use App\Domain\Tray\Kinds\TrayKind;
use App\Domain\Tray\Paste\Paste;
use App\Domain\Tray\Paste\UndoablePasteHandler;
use App\Exceptions\Refusal;
use App\Models\Customer;
use App\Models\TrayItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A person's bakje: what is on it, putting things on it, and pasting them off.
 *
 * Every paste runs inside TrayContext, so whatever it changes lands on the
 * timeline marked as done via the bakje, by the same actions the screens use.
 */
class Tray
{
    /** @var array<int, ?string> */
    private array $customer_names = [];

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
            $kind = TrayKinds::forModel($type);
            $records = $kind === null
                ? collect()
                : $kind::visible($group->pluck('trayable_id')->all(), $user)->keyBy(fn (Model $record) => $record->getKey());

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
    public function place(User $user, string $name, array $ids): void
    {
        $kind = TrayKinds::get($name);
        $ids = array_unique(array_map('intval', $ids));
        $records = $kind::visible($ids, $user);

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

        $kinds = $items->map(fn (TrayItem $item) => TrayKinds::forModel($item->trayable_type))->unique();

        if ($kinds->count() !== 1 || $kinds->first() === null) {
            throw new Refusal('Plak één soort tegelijk.');
        }

        $kind = $kinds->first();
        $records = $kind::visible($items->pluck('trayable_id')->all(), $user);

        if ($records->count() !== $items->count()) {
            throw new Refusal('Een deel hiervan kun je niet meer openen.');
        }

        $handler = TrayKinds::handler($kind, $target['type']);
        $paste = new Paste($user, $kind, $records, $target);
        $question = $handler->question($paste);

        if ($question !== null && $answer === null) {
            return ['question' => $question];
        }

        if ($question !== null && !$question->offers($answer)) {
            throw new Refusal('Die keuze is er niet meer. Plak het opnieuw.');
        }

        $paste = $paste->withAnswer($answer);
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
                'handler' => $kind::name() . ':' . $target['type'],
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
        [$name, $target] = explode(':', $undo['handler']);
        $kind = TrayKinds::get($name);
        $handler = TrayKinds::handler($kind, $target);

        if (!$handler instanceof UndoablePasteHandler) {
            throw new Refusal('Dit kun je niet ongedaan maken.');
        }

        $message = app(TrayContext::class)->run(fn () => DB::transaction(fn () => $handler->undo($user, $undo)));

        $this->put($user, $kind, $kind::visible($undo['record_ids'], $user));

        return ['message' => $message, 'items' => $this->items($user)];
    }

    /**
     * Two clicks that both arrive before either is saved both find the record
     * missing; createOrFirst lets the second take the row the first just wrote.
     *
     * @param  class-string<TrayKind>  $kind
     */
    private function put(User $user, string $kind, Collection $records): void
    {
        $position = (int) $user->trayItems()->max('position');

        foreach ($records as $record) {
            TrayItem::createOrFirst(
                ['user_id' => $user->id, 'trayable_type' => $kind::model(), 'trayable_id' => $record->getKey()],
                ['position' => ++$position],
            );
        }
    }

    /**
     * @param  class-string<TrayKind>  $kind
     * @return array<string, mixed>
     */
    private function describe(string $kind, TrayItem $item, Model $record): array
    {
        $card = $kind::describe($record);

        return [
            'id' => $item->id,
            'kind' => $kind::name(),
            'record_id' => $record->getKey(),
            'pinned' => $item->pinned,
            ...$card,
            'customer_name' => $card['customer_id'] === null ? null : $this->customerName($card['customer_id']),
        ];
    }

    private function customerName(int $customer_id): ?string
    {
        return $this->customer_names[$customer_id] ??= Customer::whereKey($customer_id)->value('name');
    }
}
