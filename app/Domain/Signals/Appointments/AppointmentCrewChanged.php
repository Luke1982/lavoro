<?php

namespace App\Domain\Signals\Appointments;

use App\Domain\Planning\Clock;
use App\Domain\Signals\BaseSignal;
use App\Domain\Signals\Users\NameListChange;
use App\Models\Customer;
use App\Models\Event;
use App\Models\ServiceOrder;
use App\Models\UserRole;
use Illuminate\Database\Eloquent\Model;

/**
 * Who executes an appointment changed: people came onto it or were taken off,
 * or someone's break, diverging times or plan roles changed. Raised by the
 * appointment itself whenever its crew is synced, so every way of planning
 * someone -- the planner, a copy, the assistant -- leaves the same line.
 *
 * Not an AppointmentSignal: a different crew is no reason to mail the customer.
 * It also goes on the werkbonnen the appointment is for, whose own crew follows
 * the appointment's without a word of its own.
 *
 * Carries the crew before and after as they were at that moment, names
 * included, so the line still reads right after someone leaves the company.
 * MajorLabel's own accounts are in both: putting one on or taking one off is
 * raised like any other change, so Google follows, but the line never names
 * them, and a change to nobody else writes no line at all.
 */
class AppointmentCrewChanged extends BaseSignal
{
    /**
     * A crew put on an appointment while it is being made travels to Google
     * with the appointment itself; only a change to an existing one needs a
     * push of its own.
     */
    public bool $appointment_is_new = false;

    /** @var array<int, string> plan role id => name, as they were called at that moment */
    public array $role_names = [];

    /**
     * @param  array<int, array<string, mixed>>  $before  see HasExecutingUsers::executingUsersSnapshot()
     * @param  array<int, array<string, mixed>>  $after
     */
    public function __construct(
        public Event $event,
        public array $before,
        public array $after,
    ) {
        parent::__construct();

        $this->appointment_is_new = $event->wasRecentlyCreated;

        $role_ids = collect([...self::named($before), ...self::named($after)])
            ->pluck('user_role_ids')->flatten()->unique()->all();
        $this->role_names = $role_ids === [] ? [] : UserRole::whereKey($role_ids)->pluck('name', 'id')->all();
    }

    public static function key(): string
    {
        return 'appointment.crew_changed';
    }

    public static function label(): string
    {
        return 'Bezetting van afspraak gewijzigd';
    }

    public function subject(): Model
    {
        return $this->event;
    }

    public function activityCategory(): string
    {
        return 'event';
    }

    public function isEmpty(): bool
    {
        return $this->before == $this->after;
    }

    public function activityDescription(): ?string
    {
        $parts = array_filter([$this->crew()->sentence('ingepland', 'eraf gehaald')]);

        foreach ($this->conditionChanges() as $change) {
            $parts[] = mb_strtolower(mb_substr($change['label'], 0, 1)) . mb_substr($change['label'], 1) . ' gewijzigd';
        }

        if ($parts === []) {
            return null;
        }

        return 'Afspraak' . $this->moment() . ': ' . implode(', ', $parts);
    }

    public function changes(): array
    {
        $rows = $this->crew()->isEmpty() ? [] : [$this->crew()->change('executing_users', 'Uitvoerenden')];

        return [...$rows, ...$this->conditionChanges()];
    }

    /**
     * The werkbonnen the appointment is for, or, for an appointment without
     * one, the customers it hangs off directly: the records a planner opens to
     * see who goes where.
     *
     * @return array<int, ServiceOrder|Customer>
     */
    public function activityContext(): array
    {
        if (!$this->event->exists) {
            return [];
        }

        $service_orders = $this->event->serviceOrders()->get();

        return ($service_orders->isEmpty() ? $this->event->customers()->get() : $service_orders)->all();
    }

    /**
     * Everyone on the crew but MajorLabel's own accounts.
     *
     * @param  array<int, array<string, mixed>>  $crew
     * @return array<int, array<string, mixed>>
     */
    private static function named(array $crew): array
    {
        return array_filter($crew, fn (array $member) => !$member['super_admin']);
    }

    private function crew(): NameListChange
    {
        return new NameListChange(
            array_map(fn (array $member) => $member['name'], self::named($this->before)),
            array_map(fn (array $member) => $member['name'], self::named($this->after)),
        );
    }

    /**
     * Break, diverging times and plan roles of everyone on the appointment
     * after the change, compared with what they had -- or with nothing, for
     * someone who just came onto it, so only what was actually set shows.
     *
     * @return array<int, array<string, ?string>>
     */
    private function conditionChanges(): array
    {
        $rows = [];
        $nothing = ['breaktime' => 0, 'diverging_start' => null, 'diverging_end' => null, 'user_role_ids' => []];

        foreach (self::named($this->after) as $user_id => $now) {
            $was = $this->before[$user_id] ?? $nothing;
            $name = $now['name'];

            if ((int) $was['breaktime'] !== (int) $now['breaktime']) {
                $rows[] = $this->row(
                    'breaktime',
                    'Pauze ' . $name,
                    (string) $was['breaktime'],
                    (string) $now['breaktime'],
                    $was['breaktime'] ? $was['breaktime'] . ' min' : null,
                    $now['breaktime'] ? $now['breaktime'] . ' min' : null,
                );
            }

            $was_times = $this->times($was);
            $now_times = $this->times($now);

            if ($was_times !== $now_times) {
                $rows[] = $this->row(
                    'diverging_times',
                    'Afwijkende tijden ' . $name,
                    $was_times,
                    $now_times,
                    $was_times,
                    $now_times,
                );
            }

            if ($was['user_role_ids'] !== $now['user_role_ids']) {
                $rows[] = $this->row(
                    'plan_roles',
                    'Planrollen ' . $name,
                    $was['user_role_ids'] === [] ? null : implode(',', $was['user_role_ids']),
                    $now['user_role_ids'] === [] ? null : implode(',', $now['user_role_ids']),
                    $this->roleNames($was['user_role_ids']),
                    $this->roleNames($now['user_role_ids']),
                );
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $member */
    private function times(array $member): ?string
    {
        if (!$member['diverging_start'] || !$member['diverging_end']) {
            return null;
        }

        return substr((string) $member['diverging_start'], 0, 5)
            . ' - ' . substr((string) $member['diverging_end'], 0, 5);
    }

    /** @param array<int, int> $ids */
    private function roleNames(array $ids): ?string
    {
        $names = array_map(fn (int $id) => $this->role_names[$id] ?? (string) $id, $ids);

        return $names === [] ? null : NameListChange::names($names);
    }

    /** @return array<string, ?string> */
    private function row(
        string $field,
        string $label,
        ?string $old,
        ?string $new,
        ?string $old_label,
        ?string $new_label,
    ): array {
        return [
            'field' => $field,
            'label' => $label,
            'old_value' => $old,
            'new_value' => $new,
            'old_label' => $old_label,
            'new_label' => $new_label,
        ];
    }

    /** The appointment named by its moment, because a werkbon can have several. */
    private function moment(): string
    {
        return $this->event->start ? ' van ' . Clock::toLocal($this->event->start)->format('d-m-Y H:i') : '';
    }
}
