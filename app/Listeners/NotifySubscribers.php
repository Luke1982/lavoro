<?php

namespace App\Listeners;

use App\Domain\Signals\Signal;
use App\Enums\UserNotificationType;
use App\Jobs\SendWebPushNotificationsJob;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\WebPushSender;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tells the people who asked to be told, and the few who do not get a say.
 * Registered by Laravel's listener discovery through the Signal type hint,
 * exactly like the activity trail, so every fact the application already raises
 * is a candidate and no creation path has to know that notifications exist. Only
 * the keys named in UserNotificationType produce anything.
 *
 * Not getting a say is the exception and it is narrow: news about your own work,
 * such as an appointment you have to carry out moving to another day. Subscribing
 * to that would be subscribing to your own agenda.
 *
 * The rows are written inside the transaction that raised the signal, so work
 * that rolls back takes its notifications with it and nobody is told about a
 * storing that never happened. Reaching a browser is the other half of the same
 * rule and cannot be undone, so that leaves as a job dispatched after the commit,
 * by which time the fact is certain.
 *
 * Whoever caused the fact is skipped. They know what they just did, and a bell
 * that rings for your own actions is a bell people stop reading.
 */
class NotifySubscribers
{
    public function __construct(private WebPushSender $sender) {}

    /**
     * A notification that cannot be written must not break the work it was about.
     * The same reasoning as the audit trail: report it and let the save stand.
     */
    public function handle(Signal $signal): void
    {
        try {
            $this->notify($signal);
        } catch (\Throwable $e) {
            Log::error('Kon meldingen niet vastleggen', [
                'signal' => $signal::key(),
                'event_key' => $signal->eventKey(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Twee bewegingen achter elkaar. Eerst wat hoe dan ook gezegd wordt, want dat
     * hangt aan het feit zelf en niet aan een keuze van de lezer; daarna wat de
     * ingetekende lezers ervan te horen krijgen.
     *
     * De volgorde is niet willekeurig: wie het bericht al persoonlijk krijgt, hoort
     * niet ook nog de algemene versie van hetzelfde moment te lezen. Één gebeurtenis
     * is één bericht.
     */
    private function notify(Signal $signal): void
    {
        $written = collect();
        $told = collect();

        foreach (UserNotificationType::mandatoryFor($signal) as $must) {
            $recipients = $must->mandatoryRecipients($signal)
                ->reject(fn (int $user_id) => $user_id === $signal->actorId())
                ->unique()
                ->values();

            $written = $written->merge($this->write($must, $signal, $recipients));
            $this->dropSuperseded($must, $signal, $recipients);
            $told = $told->merge($recipients);
        }

        $type = UserNotificationType::tryFrom($signal->eventKey());

        if ($type !== null && $type->shouldNotify($signal)) {
            $subscribers = $this->subscribersFor($type, $signal->actorId())->diff($told);

            $written = $written->merge($this->write($type, $signal, $subscribers));
        }

        if ($written->isEmpty()) {
            return;
        }

        $this->pushAfterCommit($written->all());
    }

    /**
     * Worded once for everybody, not once per person: the sentence is the same for
     * all of them, and rendering it inside the loop would ask the record about
     * itself again for every extra reader.
     *
     * @param  Collection<int, int>  $user_ids
     * @return Collection<int, int>
     */
    private function write(UserNotificationType $type, Signal $signal, Collection $user_ids): Collection
    {
        if ($user_ids->isEmpty()) {
            return collect();
        }

        $subject = $signal->subject();
        $priority = $type->priorityFor($signal);
        $title = $type->titleFor($signal);
        $body = $type->bodyFor($signal);

        return $user_ids->map(fn (int $user_id) => UserNotification::create([
            'user_id' => $user_id,
            'type' => $type->value,
            'priority' => $priority,
            'notificationable_type' => $subject->getMorphClass(),
            'notificationable_id' => $subject->getKey(),
            'title' => $title,
            'body' => $body,
        ])->id);
    }

    /**
     * Een melding die door een volgende achterhaald wordt, verdwijnt: iemand die
     * net van een afspraak gehaald is heeft niets aan het bericht dat diezelfde
     * afspraak verzet is, en dat bericht noemt bovendien de nieuwe dag.
     *
     * Alleen wat nog niet gelezen is. Wat iemand al gezien heeft weghalen is de
     * geschiedenis herschrijven, en de push die erbij hoort is dan toch al weg.
     *
     * @param  Collection<int, int>  $user_ids
     */
    private function dropSuperseded(UserNotificationType $type, Signal $signal, Collection $user_ids): void
    {
        $superseded = $type->supersedes();

        if ($superseded === [] || $user_ids->isEmpty()) {
            return;
        }

        $subject = $signal->subject();

        UserNotification::whereIn('user_id', $user_ids)
            ->whereIn('type', array_column($superseded, 'value'))
            ->where('notificationable_type', $subject->getMorphClass())
            ->where('notificationable_id', $subject->getKey())
            ->whereNull('read_at')
            ->delete();
    }

    /**
     * After the commit, always: the rows above can still disappear with a
     * rollback, and a push that has gone out cannot.
     *
     * Registered here rather than with dispatch()->afterCommit() so the queue
     * write is inside a try of its own. That call happens once the transaction
     * commits, which is past everything this class can catch, and a queue that
     * cannot be reached would otherwise fail the save that is already committed.
     *
     * @param  array<int, int>  $notification_ids
     */
    private function pushAfterCommit(array $notification_ids): void
    {
        if (!$this->sender->isConfigured()) {
            return;
        }

        DB::afterCommit(function () use ($notification_ids) {
            try {
                SendWebPushNotificationsJob::dispatch($notification_ids);
            } catch (\Throwable $e) {
                Log::error('Kon browsermeldingen niet inplannen', [
                    'notification_ids' => $notification_ids,
                    'exception' => $e,
                ]);
            }
        });
    }

    /**
     * Subscribed, not the actor, and still allowed to read the thing. The
     * permission is resolved in the query rather than per user, because this runs
     * in the middle of somebody's save.
     *
     * @return Collection<int, int>
     */
    private function subscribersFor(UserNotificationType $type, ?int $actor_id): Collection
    {
        $required = $type->requiredPermissions();

        return User::query()
            ->whereHas('notificationSubscriptions', fn ($query) => $query->where('type', $type->value))
            ->when($actor_id !== null, fn ($query) => $query->whereKeyNot($actor_id))
            ->when($required !== [], fn ($query) => $query->where(fn ($allowed) => $allowed
                ->whereHas('roles', fn ($roles) => $roles->where('name', 'admin'))

                /** Alle rechten, niet een ervan: ze staan er samen, niet als keuze. */
                ->orWhere(function ($holder) use ($required) {
                    foreach ($required as $permission) {
                        $holder->whereHas(
                            'roles.permissions',
                            fn ($permissions) => $permissions->where('name', $permission)
                        );
                    }
                })))
            ->pluck('id');
    }
}
