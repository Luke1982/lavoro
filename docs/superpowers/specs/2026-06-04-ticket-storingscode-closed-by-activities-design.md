# Ticket: storingscode, closed_by en activiteiten

## Scope

Voegt drie dingen toe aan tickets:

1. Vrij tekstveld `status_code` ("Storingscode") — de diagnose/oorzaakcode die een monteur invult.
2. `closed_by_id` FK naar users — automatisch gevuld wanneer een ticket op "Gesloten" wordt gezet, geleegd wanneer het heropend wordt.
3. Activiteitenlog — elke status- of prioriteitswijziging op een ticket wordt gelogd als `Activity`, zichtbaar in de tickethistorie.

---

## Database

**Migratie** — één nieuwe migratie (`2026_06_04_add_storingscode_and_closed_by_to_tickets_table`):

```php
$table->string('status_code')->nullable()->after('priority');
$table->foreignIdFor(User::class, 'closed_by_id')->nullable()->constrained('users')->nullOnDelete()->after('status_code');
```

Beide kolommen zijn nullable; bestaande tickets blijven ongewijzigd.

---

## Backend

### Ticket model

- Voeg `HasActivities` trait toe.
- Voeg `'status_code'` en `'closed_by_id'` toe aan `$fillable`.
- Voeg relatie toe:

```php
public function closedBy(): BelongsTo
{
    return $this->belongsTo(User::class, 'closed_by_id');
}
```

### Activity model

Voeg de inverse morphToMany toe (parallel aan bestaande `serviceOrders()`):

```php
public function tickets(): MorphToMany
{
    return $this->morphedByMany(Ticket::class, 'activityable')->withTimestamps();
}
```

### TicketObserver

Nieuw bestand `app/Observers/TicketObserver.php`. Registreren in `AppServiceProvider::boot()` via `Ticket::observe(TicketObserver::class)`.

Alleen de **`updated(Ticket $ticket)`** hook is nodig. Na het opslaan zijn `$ticket->getChanges()` (wat er veranderde) en `$ticket->getOriginal()` (de waarden vóór de save) allebei beschikbaar:

- Als `status` in `getChanges()` zit: log `"Status gewijzigd van '{oud}' naar '{nieuw}'"` (category: `'status'`).
- Als `priority` in `getChanges()` zit: log `"Prioriteit gewijzigd van '{oud}' naar '{nieuw}'"` (category: `'status'`).
- Als nieuwe status `'Gesloten'` is: `$ticket->closed_by_id = Auth::id(); $ticket->closed_on = now(); $ticket->saveQuietly();`
- Als nieuwe status _niet_ `'Gesloten'` is én het ticket eerder gesloten was (`getOriginal('status') === 'Gesloten'`): wis `closed_by_id` en `closed_on` via `saveQuietly()`.

`saveQuietly()` voorkomt dat de observer zichzelf recursief aanroept.

### TicketUpdateRequest

Voeg toe aan `rules()`:

```php
'status_code' => 'nullable|string|max:255',
```

### TicketController

- `update()`: verwijder de bestaande `closed_on` if/elseif blokken — de observer regelt nu zowel `closed_on` als `closed_by_id`.
- `show()`: laad `closedBy` relatie zodat de frontend de naam kan tonen.

---

## Frontend

### ShowPage.vue

Voeg een rij toe in het detailgrid voor `Storingscode`, tussen omschrijving en status:

```vue
<div class="col-span-12 md:col-span-2">
    <span class="text-xs font-bold">Storingscode</span>
</div>
<div class="col-span-12 md:col-span-10">
    <EditableTextField v-model="form.status_code" class="w-full"
        :readonly="!hasPermission('ticket.update')" />
</div>
```

Bind `form.status_code` aan het initiële `ticket.status_code`. Zorg dat `status_code` meegestuurd wordt bij het opslaan van het formulier.

Toon ook `closed_by` wanneer het ticket gesloten is:

```vue
<span v-if="ticket.closed_by">
    Gesloten door {{ ticket.closed_by.name }}
</span>
```

### TicketCard.vue

Toon `status_code` als leesonly badge/tekst onder de omschrijving wanneer het gevuld is:

```vue
<p v-if="ticket.status_code" class="text-xs text-gray-400 mt-1">
    Storingscode: {{ ticket.status_code }}
</p>
```

---

## Wat valt buiten scope

- Geen nieuwe permissies voor `status_code` — valt onder bestaande `ticket.update`.
- Geen aparte activiteitenweergave in de UI — activiteiten zijn beschikbaar via de `activities` relatie maar er wordt geen nieuw activiteitenpaneel gebouwd.
- Geen wijzigingen aan `TicketCard` status-actie logica — de observer vangt alles af.
