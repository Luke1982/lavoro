<?php

namespace App\Models;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\Capability;
use App\Domain\Integrations\Connector;
use App\Domain\Integrations\Connectors;
use App\Domain\Integrations\DocumentForm;
use App\Domain\Integrations\Leading;
use App\Models\Traits\RecordsHistory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;

/**
 * One outside system this customer uses, with its credentials and, per area,
 * what goes which way.
 *
 * settings holds one block per area plus the connector's own options:
 *
 *   customers, materials, ledger_accounts (Area::synced()):
 *                   read, write, leading (external|lavoro)
 *   service_orders: form (a DocumentForm, or null for "not sent")
 *   options:        whatever Connector::optionFields() asks for
 *
 * A switch only counts when the connector has the capability behind it, so a
 * stale setting never makes Lavoro call something the connector cannot do.
 */
class IntegrationConnection extends Model
{
    use RecordsHistory;

    protected $fillable = [
        'connector',
        'settings',
        'is_active',
        'last_synced_at',
        'last_error',
        'last_error_at',
    ];

    protected $hidden = ['credentials'];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    protected array $activity_labels = [
        'connector' => 'Systeem',
        'settings' => 'Instellingen',
        'is_active' => 'Actief',
    ];

    protected array $activity_ignore = ['credentials', 'last_synced_at', 'last_error', 'last_error_at'];

    /**
     * Everything the connector can read is switched on to start with, nothing
     * that writes customers: sending Lavoro's customers into someone's books is
     * a choice to make, not a default. A werkbon goes in the first form the
     * connector offers, which is never one that books it.
     *
     * @return array<string, mixed>
     */
    public static function defaultSettings(string $connector): array
    {
        $class = Connectors::classFor($connector);
        $settings = [];

        foreach (Area::synced() as $area) {
            $read = Capability::read($area);
            $settings[$area->value] = ['read' => $read && $class::can($read), 'write' => false, 'leading' => Leading::External->value];
        }

        $settings[Area::ServiceOrders->value] = ['form' => ($class::documentFormsOffered()[0] ?? null)?->value];
        $settings['options'] = [];

        return $settings;
    }

    /** A new connection to this system, with the default settings. Not saved yet. */
    public static function open(string $connector): self
    {
        return new self([
            'connector' => $connector,
            'is_active' => true,
            'settings' => self::defaultSettings($connector),
        ]);
    }

    /**
     * Takes what the settings screen sent, as its form request validated it.
     * A credential left empty keeps the stored one, because a secret is never
     * sent back to the screen; an option or switch that was not sent keeps its
     * value too.
     *
     * The credentials are only written when something was filled in.
     * Encrypting gives a new value every time, so rewriting them anyway would
     * save them as they were read -- and put back a refresh token that was
     * renewed meanwhile, which Exact has already killed.
     *
     * @param  array{is_active?: bool, credentials?: array<string, ?string>, options?: array<string, ?string>, areas?: array<string, array<string, mixed>>}  $form
     */
    public function configure(array $form): void
    {
        $class = $this->connectorClass();
        $settings = array_replace_recursive($this->settings ?? [], $form['areas'] ?? []);
        $credentials = $this->credentials();

        foreach ($class::optionFields() as $field) {
            if (array_key_exists($field->key, $form['options'] ?? [])) {
                $settings['options'][$field->key] = $form['options'][$field->key];
            }
        }

        foreach ($class::credentialFields() as $field) {
            if (filled($form['credentials'][$field->key] ?? null)) {
                $credentials[$field->key] = trim((string) $form['credentials'][$field->key]);
            }
        }

        $this->settings = $settings;
        $this->is_active = $form['is_active'] ?? $this->is_active;

        if ($credentials !== $this->credentials()) {
            $this->storeCredentials($credentials);
        }
    }

    public function links(): HasMany
    {
        return $this->hasMany(Integrationable::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(IntegrationRun::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(IntegrationWebhook::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function connector(): Connector
    {
        return Connectors::make($this);
    }

    /** @return class-string<Connector> */
    public function connectorClass(): string
    {
        return Connectors::classFor($this->connector);
    }

    public function label(): string
    {
        return Connectors::exists($this->connector) ? $this->connectorClass()::label() : $this->connector;
    }

    /**
     * After a change of APP_KEY nothing deciphers any more. That reads as "no
     * credentials", which the screen shows as keys to enter again, rather than
     * an error on every page that asks.
     *
     * @return array<string, string>
     */
    public function credentials(): array
    {
        $raw = $this->attributes['credentials'] ?? null;

        if (!filled($raw)) {
            return [];
        }

        try {
            return json_decode(Crypt::decryptString($raw), true) ?: [];
        } catch (DecryptException) {
            return [];
        }
    }

    /** @param  array<string, ?string>  $credentials */
    public function storeCredentials(array $credentials): void
    {
        $credentials = array_filter($credentials, fn ($value) => filled($value));

        $this->attributes['credentials'] = $credentials === [] ? null : Crypt::encryptString(json_encode($credentials));
    }

    public function hasCredential(string $key): bool
    {
        return filled($this->credentials()[$key] ?? null);
    }

    public function option(string $key): mixed
    {
        return $this->settings['options'][$key] ?? null;
    }

    public function setOption(string $key, ?string $value): void
    {
        $settings = $this->settings ?? [];
        $settings['options'][$key] = $value;
        $this->settings = $settings;
    }

    public function areaSetting(Area $area, string $key, mixed $default = null): mixed
    {
        return $this->settings[$area->value][$key] ?? $default;
    }

    public function can(Capability $capability): bool
    {
        return Connectors::exists($this->connector) && $this->connectorClass()::can($capability);
    }

    public function reads(Area $area): bool
    {
        $capability = Capability::read($area);

        return $this->is_active && $capability && $this->can($capability)
            && (bool) $this->areaSetting($area, 'read', false);
    }

    public function writes(Area $area): bool
    {
        if ($area === Area::ServiceOrders) {
            return $this->documentForm() !== null;
        }

        $capability = Capability::write($area);

        return $this->is_active && $capability && $this->can($capability)
            && (bool) $this->areaSetting($area, 'write', false);
    }

    /** Whether anything of this area goes either way. */
    public function exchanges(Area $area): bool
    {
        return $this->reads($area) || $this->writes($area);
    }

    public function leading(Area $area): Leading
    {
        return Leading::tryFrom((string) $this->areaSetting($area, 'leading')) ?? Leading::External;
    }

    /** The form a werkbon takes there, when this connection receives werkbonnen at all. */
    public function documentForm(): ?DocumentForm
    {
        if (!$this->is_active || !$this->can(Capability::ServiceOrdersWrite)) {
            return null;
        }

        $form = DocumentForm::tryFrom((string) $this->areaSetting(Area::ServiceOrders, 'form'));

        return $form && in_array($form, $this->connectorClass()::documentForms(), true) ? $form : null;
    }

    public function noteSuccess(): void
    {
        $this->forceFill(['last_synced_at' => now(), 'last_error' => null, 'last_error_at' => null])->saveQuietly();
    }

    public function noteFailure(string $message): void
    {
        $this->forceFill(['last_error' => $message, 'last_error_at' => now()])->saveQuietly();
    }

    /** @return Collection<int, self> */
    public static function writing(Area $area): Collection
    {
        return self::active()->get()->filter(fn (self $connection) => $connection->writes($area))->values();
    }

    /** @return Collection<int, self> */
    public static function reading(Area $area): Collection
    {
        return self::active()->get()->filter(fn (self $connection) => $connection->reads($area))->values();
    }

    /**
     * The "fetch from ..." buttons on a list page: the systems this person may
     * pull this area from.
     *
     * @return array<int, array{id: int, label: string}>
     */
    public static function pullButtons(Area $area, ?User $user): array
    {
        if (!$user?->hasAnyPermission(['integration.pull', 'integration.manage'])) {
            return [];
        }

        return self::reading($area)
            ->map(fn (self $connection) => ['id' => $connection->id, 'label' => $connection->label()])
            ->all();
    }
}
