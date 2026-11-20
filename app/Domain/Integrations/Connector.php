<?php

namespace App\Domain\Integrations;

use App\Domain\Integrations\Contracts\ConnectsWithOAuth;
use App\Domain\Integrations\Contracts\PullsCustomers;
use App\Domain\Integrations\Contracts\PullsLedgerAccounts;
use App\Domain\Integrations\Contracts\PullsMaterials;
use App\Domain\Integrations\Contracts\ReceivesSalesDocuments;
use App\Domain\Integrations\Data\CustomerData;
use App\Domain\Integrations\Data\Field;
use App\Domain\Integrations\Data\LedgerAccountData;
use App\Domain\Integrations\Data\MaterialData;
use App\Exceptions\Refusal;
use App\Models\IntegrationConnection;
use Closure;
use Throwable;

/**
 * One outside system, as Lavoro talks to it. A connector only translates:
 * Lavoro's standard shapes (CustomerData, MaterialData, LedgerAccountData,
 * SalesDocumentData) to that system's API and back. It knows nothing of
 * models, links or sync rules; those are in Sync and are the same for every
 * connector.
 *
 * What it can do is what it implements: each capability is an interface in
 * Contracts (see Capability). A new connector is a subclass here, the
 * interfaces it can honour, and a line in Connectors.
 */
abstract class Connector
{
    public function __construct(protected IntegrationConnection $connection) {}

    /** Stored on the connection; never rename it. */
    abstract public static function key(): string;

    abstract public static function label(): string;

    abstract public static function description(): string;

    /** @return array<int, Field> What it needs to log in. */
    abstract public static function credentialFields(): array;

    /**
     * Throws a Refusal saying what is wrong when the credentials or the
     * settings do not work; the "Test" button on the settings screen.
     */
    abstract public function test(): void;

    /** @return array<int, Field> Further settings, such as an invoice template. */
    public static function optionFields(): array
    {
        return [];
    }

    /** @return array<int, string> The CustomerData fields this system has. */
    public static function customerFields(): array
    {
        return CustomerData::FIELDS;
    }

    /** @return array<int, string> The MaterialData fields this system has. */
    public static function materialFields(): array
    {
        return MaterialData::FIELDS;
    }

    /** @return array<int, string> The LedgerAccountData fields this system has. */
    public static function ledgerAccountFields(): array
    {
        return LedgerAccountData::FIELDS;
    }

    /** @return array<int, Capability> */
    public static function capabilities(): array
    {
        return array_values(array_filter(Capability::cases(), fn (Capability $capability) => static::can($capability)));
    }

    public static function can(Capability $capability): bool
    {
        return is_subclass_of(static::class, $capability->contract());
    }

    /** How a pull of this area finds what changed; null when this system cannot be pulled for it. */
    public static function changeDetection(Area $area): ?ChangeDetection
    {
        return match (true) {
            $area === Area::Customers && is_subclass_of(static::class, PullsCustomers::class) => static::customerChanges(),
            $area === Area::Materials && is_subclass_of(static::class, PullsMaterials::class) => static::materialChanges(),
            $area === Area::LedgerAccounts && is_subclass_of(static::class, PullsLedgerAccounts::class) => static::ledgerAccountChanges(),
            default => null,
        };
    }

    /** @return array<int, DocumentForm> What a werkbon can become there; none when it takes no werkbonnen. */
    public static function documentFormsOffered(): array
    {
        return is_subclass_of(static::class, ReceivesSalesDocuments::class) ? static::documentForms() : [];
    }

    /** @return array<string, mixed> What the settings screen needs to know about this connector. */
    public static function describe(): array
    {
        $change_detection = [];

        foreach (Area::cases() as $area) {
            if ($detection = static::changeDetection($area)) {
                $change_detection[$area->value] = $detection->label();
            }
        }

        return [
            'key' => static::key(),
            'label' => static::label(),
            'description' => static::description(),
            'credential_fields' => array_map(fn (Field $field) => $field->toArray(), static::credentialFields()),
            'option_fields' => array_map(fn (Field $field) => $field->toArray(), static::optionFields()),
            'capabilities' => array_map(
                fn (Capability $capability) => ['key' => $capability->value, 'label' => $capability->label()],
                static::capabilities(),
            ),
            'change_detection' => $change_detection,
            'document_forms' => array_map(
                fn (DocumentForm $form) => ['id' => $form->value, 'name' => $form->label()],
                static::documentFormsOffered(),
            ),
            'oauth' => static::connectsWithOAuth(),
            'oauth_ready' => static::connectsWithOAuth() && static::oauthReady(),
        ];
    }

    /** Whether the customer connects by logging in there (ConnectsWithOAuth) instead of typing in keys. */
    public static function connectsWithOAuth(): bool
    {
        return is_subclass_of(static::class, ConnectsWithOAuth::class);
    }

    /**
     * Undoes a half-made document after $failure, and returns what to throw:
     * the failure itself, or -- when undoing is refused too -- a refusal that
     * also says what was left behind there, for someone to remove by hand.
     */
    protected function undoAfter(Throwable $failure, Closure $undo, string $left_behind): Throwable
    {
        try {
            $undo();
        } catch (Refusal $e) {
            if ($failure instanceof Refusal) {
                return new Refusal($failure->getMessage() . ' ' . $left_behind . ' staat nog in ' . static::label()
                    . ' en moet daar met de hand weg: ' . $e->getMessage(), 0, $failure);
            }
        }

        return $failure;
    }

    protected function credential(string $key): ?string
    {
        $value = $this->connection->credentials()[$key] ?? null;

        return filled($value) ? (string) $value : null;
    }

    /** The connection's own value, or else the field's default. */
    protected function option(string $key): ?string
    {
        $value = $this->connection->option($key);

        return filled($value) ? (string) $value : collect(static::optionFields())->firstWhere('key', $key)?->default;
    }
}
