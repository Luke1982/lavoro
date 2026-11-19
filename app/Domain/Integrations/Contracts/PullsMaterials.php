<?php

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\ChangeDetection;
use App\Domain\Integrations\Data\ExternalRecord;
use Carbon\CarbonImmutable;

/**
 * The other side can hand over its articles, which become materials here.
 */
interface PullsMaterials
{
    public static function materialChanges(): ChangeDetection;

    /**
     * Every article, or with Delta only those changed since $since. Each
     * record's data is a MaterialData.
     *
     * @return iterable<int, ExternalRecord>
     */
    public function pullMaterials(?CarbonImmutable $since = null): iterable;

    public function fetchMaterial(string $id): ?ExternalRecord;
}
