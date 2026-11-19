<?php

namespace App\Domain\Integrations\Sync;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\Connector;
use App\Domain\Integrations\Contracts\PullsCustomers;
use App\Domain\Integrations\Contracts\PushesCustomers;
use App\Domain\Integrations\Data\CustomerData;
use App\Domain\Integrations\Data\ExternalRecord;
use App\Domain\Integrations\Data\RecordData;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class CustomerMapping extends Mapping
{
    /**
     * A KvK or VAT number names exactly one company; otherwise name and
     * postcode together. Never a name alone: two customers called "Bakkerij de
     * Vries" are not the same one.
     */
    protected const MATCH_ON = [['chamber_of_commerce_number'], ['vat_number'], ['name', 'postal_code']];

    public function area(): Area
    {
        return Area::Customers;
    }

    public function fields(Connector $connector): array
    {
        return $connector::customerFields();
    }

    public function dataFrom(Model $model): RecordData
    {
        /** @var Customer $model */
        return CustomerData::fromCustomer($model);
    }

    public function pull(Connector $connector, ?CarbonImmutable $since): iterable
    {
        /** @var PullsCustomers $connector */
        return $connector->pullCustomers($since);
    }

    public function fetch(Connector $connector, string $id): ?ExternalRecord
    {
        /** @var PullsCustomers $connector */
        return $connector->fetchCustomer($id);
    }

    public function create(Connector $connector, RecordData $data): ExternalRecord
    {
        /** @var PushesCustomers $connector */
        /** @var CustomerData $data */
        return $connector->createCustomer($data);
    }

    public function update(Connector $connector, string $id, RecordData $data): ExternalRecord
    {
        /** @var PushesCustomers $connector */
        /** @var CustomerData $data */
        return $connector->updateCustomer($id, $data);
    }
}
