<?php

namespace App\Models;

use App\Models\Traits\RecordsHistory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Contact extends Model
{
    use RecordsHistory;

    protected $fillable = ['first_name', 'last_name', 'email', 'phone', 'mobile'];

    protected $appends = ['full_name'];

    protected array $activity_labels = [
        'first_name' => 'Voornaam',
        'last_name' => 'Achternaam',
        'email' => 'E-mail',
        'phone' => 'Telefoon',
        'mobile' => 'Mobiel',
    ];

    /** The records that name a contact person, each picked from its own customer. */
    private const NAMED_ON = [Project::class, Quote::class, ServiceOrder::class];

    /**
     * The customers are read before the row goes, because the database takes the
     * links along with it and the removal still belongs on their timelines.
     */
    protected static function booted(): void
    {
        static::deleting(function (Contact $contact) {
            $contact->loadMissing('customers');
            $contact->release();
        });
    }

    /**
     * Takes this person off every project, quote and werkbon that names them, or
     * only off those of one customer. One by one, so each says on its own
     * timeline who its contact was; the database would clear the column by
     * itself, without a word.
     */
    public function release(?Customer $customer = null): void
    {
        foreach (self::NAMED_ON as $model) {
            $model::where('contact_id', $this->id)
                ->when($customer, fn ($query) => $query->where('customer_id', $customer->id))
                ->get()
                ->each(fn (Model $record) => $record->update(['contact_id' => null]));
        }
    }

    /**
     * Creates the contact already linked to its customer. The link can only be
     * written once the row exists, but the entry saying it was created is written
     * as the row is, and it belongs on that customer's timeline.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createFor(Customer $customer, array $attributes): self
    {
        return DB::transaction(function () use ($customer, $attributes) {
            $contact = new self($attributes);
            $contact->setRelation('customers', new Collection([$customer]));
            $contact->save();
            $contact->customers()->attach($customer->id);

            return $contact;
        });
    }

    /** A contact has no timeline of its own: what happens to it is news at its customers. */
    public function historyTimelines(string $action): array
    {
        return $this->customers->all();
    }

    public function historyLabel(): ?string
    {
        return 'Contactpersoon ' . trim($this->full_name);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function customers()
    {
        return $this->morphedByMany(Customer::class, 'contactable')->withTimestamps();
    }
}
