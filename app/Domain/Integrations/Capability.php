<?php

namespace App\Domain\Integrations;

use App\Domain\Integrations\Contracts\PullsCustomers;
use App\Domain\Integrations\Contracts\PullsLedgerAccounts;
use App\Domain\Integrations\Contracts\PullsMaterials;
use App\Domain\Integrations\Contracts\PushesCustomers;
use App\Domain\Integrations\Contracts\PushesLedgerAccounts;
use App\Domain\Integrations\Contracts\ReceivesSalesDocuments;
use App\Domain\Integrations\Contracts\ReceivesWebhooks;

/**
 * Everything a connector can be able to do, each one backed by an interface.
 * A connector has a capability by implementing its interface, so it cannot
 * claim one without the code behind it, and the settings screen reads the list
 * from here instead of keeping one of its own.
 *
 * A new capability is a case here, an interface in Contracts, and the code in
 * Sync that uses it.
 */
enum Capability: string
{
    case CustomersRead = 'customers.read';
    case CustomersWrite = 'customers.write';
    case MaterialsRead = 'materials.read';
    case ServiceOrdersWrite = 'service_orders.write';
    case LedgerAccountsRead = 'ledger_accounts.read';
    case LedgerAccountsWrite = 'ledger_accounts.write';
    case Webhooks = 'webhooks';

    /** @return class-string */
    public function contract(): string
    {
        return match ($this) {
            self::CustomersRead => PullsCustomers::class,
            self::CustomersWrite => PushesCustomers::class,
            self::MaterialsRead => PullsMaterials::class,
            self::ServiceOrdersWrite => ReceivesSalesDocuments::class,
            self::LedgerAccountsRead => PullsLedgerAccounts::class,
            self::LedgerAccountsWrite => PushesLedgerAccounts::class,
            self::Webhooks => ReceivesWebhooks::class,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CustomersRead => 'Klanten ophalen',
            self::CustomersWrite => 'Klanten versturen',
            self::MaterialsRead => 'Materialen ophalen',
            self::ServiceOrdersWrite => 'Werkbonnen versturen',
            self::LedgerAccountsRead => 'Grootboekrekeningen ophalen',
            self::LedgerAccountsWrite => 'Grootboekrekeningen versturen',
            self::Webhooks => 'Meldt zelf wijzigingen',
        };
    }

    public function area(): ?Area
    {
        return match ($this) {
            self::CustomersRead, self::CustomersWrite => Area::Customers,
            self::MaterialsRead => Area::Materials,
            self::ServiceOrdersWrite => Area::ServiceOrders,
            self::LedgerAccountsRead, self::LedgerAccountsWrite => Area::LedgerAccounts,
            self::Webhooks => null,
        };
    }

    public static function read(Area $area): ?self
    {
        return match ($area) {
            Area::Customers => self::CustomersRead,
            Area::Materials => self::MaterialsRead,
            Area::ServiceOrders => null,
            Area::LedgerAccounts => self::LedgerAccountsRead,
        };
    }

    public static function write(Area $area): ?self
    {
        return match ($area) {
            Area::Customers => self::CustomersWrite,
            Area::Materials => null,
            Area::ServiceOrders => self::ServiceOrdersWrite,
            Area::LedgerAccounts => self::LedgerAccountsWrite,
        };
    }
}
