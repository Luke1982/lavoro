<?php

namespace App\Http\Requests;

use App\Enums\UserNotificationType;
use App\Models\NotificationSubscription;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationSubscriptionStoreRequest extends FormRequest
{
    /**
     * Wat je als los record kunt volgen. Een open lijst zou betekenen dat een
     * verzoek zelf mag kiezen welke klasse er uit de database gehaald wordt.
     *
     * @var array<int, class-string<Model>>
     */
    private const FOLLOWABLE = [
        Ticket::class,
    ];

    /** False until looked up, which is not the same as looked up and not found. */
    private User|false|null $resolved_subscriber = false;

    /** Idem: false betekent nog niet gezocht, null betekent gezocht en niets gevonden. */
    private Model|false|null $resolved_record = false;

    public function authorize(): bool
    {
        return $this->user()->can('create', [NotificationSubscription::class, $this->subscriber()]);
    }

    public function rules(): array
    {
        return [
            /**
             * Trashed users are excluded on purpose: a plain exists rule counts
             * them, and the row would then pass validation only to be looked up as
             * nothing at all.
             */
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
            ],

            /**
             * Zonder record gaat het over een soort nieuws en moet dat soort er
             * staan. Mét record mag het leeg blijven: dat is "alles wat hierover
             * te melden valt".
             */
            'type' => [
                Rule::requiredIf(fn () => !$this->filled('subscribable_type')),
                'nullable',
                Rule::in(array_column(UserNotificationType::subscribableCases(), 'value')),
                fn (string $attribute, mixed $value, callable $fail) => $this->rejectUnreadableType($value, $fail),
                fn (string $attribute, mixed $value, callable $fail) => $this->rejectDuplicate($fail, $attribute),
            ],

            'subscribable_type' => [
                'nullable',
                'required_with:subscribable_id',
                Rule::in(self::FOLLOWABLE),
            ],

            'subscribable_id' => [
                'nullable',
                'required_with:subscribable_type',
                'integer',
                fn (string $attribute, mixed $value, callable $fail) => $this->rejectUnviewableRecord($fail),
                fn (string $attribute, mixed $value, callable $fail) => $this->rejectDuplicate($fail, $attribute),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Het meldingstype is verplicht.',
            'type.in' => 'Dit meldingstype kan niet aan- of uitgezet worden.',
            'user_id.exists' => 'De opgegeven gebruiker bestaat niet.',
            'subscribable_type.in' => 'Dit soort record kan niet gevolgd worden.',
            'subscribable_type.required_with' => 'Er is niet opgegeven wat er gevolgd wordt.',
            'subscribable_id.required_with' => 'Er is niet opgegeven wat er gevolgd wordt.',
        ];
    }

    /**
     * Nobody, themselves included, is signed up for news they may not read. The
     * warning names the person, because the one setting this is often not the one
     * who would be missing the permission.
     *
     * Alleen voor abonnementen op een soort: wie één record volgt wordt op zicht
     * op dat record afgerekend, en dat is de vraag hieronder.
     */
    private function rejectUnreadableType(mixed $value, callable $fail): void
    {
        $subscriber = $this->subscriber();
        $type = is_string($value) ? UserNotificationType::tryFrom($value) : null;

        if (!$subscriber || !$type || $this->filled('subscribable_type')) {
            return;
        }

        if ($subscriber->hasEveryPermission($type->requiredPermissions())) {
            return;
        }

        $about = 'de rechten niet om meldingen van het type ' . $type->label() . ' te ontvangen.';

        $fail($subscriber->id === $this->user()->id
            ? 'Je hebt ' . $about
            : $subscriber->name . ' heeft ' . $about);
    }

    /**
     * Volgen kan alleen wat je ziet. Niet het brede leesrecht van het soort: wie
     * een werkbon uitvoert ziet de storingen erop, en die moet hij kunnen volgen
     * zonder een recht dat hem alle storingen zou tonen.
     */
    private function rejectUnviewableRecord(callable $fail): void
    {
        if (!$this->filled('subscribable_type')) {
            return;
        }

        $subscriber = $this->subscriber();
        $record = $this->record();

        if (!$subscriber) {
            return;
        }

        if ($record === null) {
            $fail('Dit record bestaat niet.');

            return;
        }

        $visible = method_exists($record, 'scopeVisibleTo')
            && $record->newQuery()->visibleTo($subscriber)->whereKey($record->getKey())->exists();

        if ($visible) {
            return;
        }

        $fail($subscriber->id === $this->user()->id
            ? 'Je kunt dit niet volgen, want je mag het niet inzien.'
            : $subscriber->name . ' kan dit niet volgen, want diegene mag het niet inzien.');
    }

    /**
     * Met de hand uitgeschreven en niet met Rule::unique, want de sleutel bevat
     * kolommen die leeg mogen zijn: MySQL rekent twee NULL's als verschillend en
     * zou dezelfde rij een tweede keer doorlaten.
     */
    private function rejectDuplicate(callable $fail, string $attribute): void
    {
        $identifying = $this->filled('subscribable_type') ? 'subscribable_id' : 'type';

        if ($attribute !== $identifying) {
            return;
        }

        $exists = NotificationSubscription::query()
            ->where('user_id', $this->subscriberId())
            ->where(fn ($query) => $this->input('type') === null
                ? $query->whereNull('type')
                : $query->where('type', $this->input('type')))
            ->where(fn ($query) => $this->filled('subscribable_type')
                ? $query->where('subscribable_type', $this->input('subscribable_type'))
                    ->where('subscribable_id', $this->input('subscribable_id'))
                : $query->whereNull('subscribable_type'))
            ->exists();

        if ($exists) {
            $fail('Dit abonnement bestaat al.');
        }
    }

    /**
     * The subscription is for whoever is named, and for the person asking when
     * nobody is. Resolved once: authorisation, two rules and the controller all
     * ask, and none of them needs its own query to find out.
     */
    public function subscriber(): ?User
    {
        if ($this->resolved_subscriber !== false) {
            return $this->resolved_subscriber;
        }

        $user_id = $this->input('user_id');

        return $this->resolved_subscriber = $user_id === null
            ? $this->user()
            : User::find($user_id);
    }

    /** Het record dat gevolgd wordt, of null als er geen is of het niet bestaat. */
    public function record(): ?Model
    {
        if ($this->resolved_record !== false) {
            return $this->resolved_record;
        }

        $type = $this->input('subscribable_type');

        if (!is_string($type) || !in_array($type, self::FOLLOWABLE, true)) {
            return $this->resolved_record = null;
        }

        return $this->resolved_record = $type::find($this->input('subscribable_id'));
    }

    private function subscriberId(): ?int
    {
        return $this->subscriber()?->id;
    }
}
