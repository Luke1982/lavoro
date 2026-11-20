<?php

namespace App\Domain\Integrations;

use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\Material;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * What a connection can exchange with Lavoro. Each area has its own switches on
 * a connection (read, write, which side leads), and the connector's
 * capabilities decide which of those switches exist at all.
 */
enum Area: string
{
    case Customers = 'customers';
    case Materials = 'materials';
    case ServiceOrders = 'service_orders';
    case LedgerAccounts = 'ledger_accounts';

    /**
     * The areas kept in step in both directions. Werkbonnen are not among
     * them: they are sent, once, when someone presses the button.
     *
     * @return array<int, self>
     */
    public static function synced(): array
    {
        return [self::Customers, self::Materials, self::LedgerAccounts];
    }

    public function label(): string
    {
        return match ($this) {
            self::Customers => 'Klanten',
            self::Materials => 'Materialen',
            self::ServiceOrders => 'Werkbonnen',
            self::LedgerAccounts => 'Grootboekrekeningen',
        };
    }

    /** @return class-string<Model> */
    public function modelClass(): string
    {
        return match ($this) {
            self::Customers => Customer::class,
            self::Materials => Material::class,
            self::ServiceOrders => ServiceOrder::class,
            self::LedgerAccounts => LedgerAccount::class,
        };
    }

    /** How a link names a record of this area. */
    public function morphClass(): string
    {
        return (new ($this->modelClass()))->getMorphClass();
    }

    public static function forModel(Model $model): ?self
    {
        foreach (self::cases() as $area) {
            if ($model instanceof ($area->modelClass())) {
                return $area;
            }
        }

        return null;
    }
}
