<?php

namespace App\Domain\Integrations\Sync;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\Connector;
use App\Domain\Integrations\Contracts\PullsMaterials;
use App\Domain\Integrations\Data\ExternalRecord;
use App\Domain\Integrations\Data\MaterialData;
use App\Domain\Integrations\Data\RecordData;
use App\Models\Material;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Materials only come in: no connector takes articles from Lavoro yet. Adding
 * that is a PushesMaterials contract, a capability, and create/update here.
 */
class MaterialMapping extends Mapping
{
    /** An article code is unique in a bookkeeping system, as it is here. */
    protected const MATCH_ON = [['code']];

    public function area(): Area
    {
        return Area::Materials;
    }

    public function fields(Connector $connector): array
    {
        return $connector::materialFields();
    }

    public function dataFrom(Model $model): RecordData
    {
        /** @var Material $model */
        return MaterialData::fromMaterial($model);
    }

    public function pull(Connector $connector, ?CarbonImmutable $since): iterable
    {
        /** @var PullsMaterials $connector */
        return $connector->pullMaterials($since);
    }

    public function fetch(Connector $connector, string $id): ?ExternalRecord
    {
        /** @var PullsMaterials $connector */
        return $connector->fetchMaterial($id);
    }

    public function create(Connector $connector, RecordData $data): ExternalRecord
    {
        throw new LogicException('No connector sends materials yet.');
    }

    public function update(Connector $connector, string $id, RecordData $data): ExternalRecord
    {
        throw new LogicException('No connector sends materials yet.');
    }

    /** No stock figure there is "not known", not "none": the column needs a number. */
    protected function values(array $values): array
    {
        $values = parent::values($values);

        if (($values['stock'] ?? null) === null) {
            unset($values['stock']);
        }

        return $values;
    }
}
