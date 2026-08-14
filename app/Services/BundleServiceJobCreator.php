<?php

namespace App\Services;

use App\Enums\ServiceJobOutcomes;
use App\Models\ServiceJob;
use Illuminate\Database\Eloquent\Collection;

class BundleServiceJobCreator
{
    private const PART_RELATIONS = [
        'asset.childAssets.product.brand',
        'asset.childAssets.product.productType',
    ];

    /**
     * Books the parts of a bundle onto the same werkbon as the bundle itself.
     * A bundle keuring without its onderdelen is half a keuring, so every path
     * that puts a bundle on a werkbon runs through here.
     *
     * A part that already has a keuring on this werkbon keeps it and is only
     * adopted — never doubled.
     *
     * @return array<int, ServiceJob> the keuringen that did not exist yet, each with its asset loaded
     */
    public function createFor(ServiceJob $parent_job): array
    {
        $parent_job->loadMissing(self::PART_RELATIONS);

        $created = [];

        foreach ($parent_job->asset?->childAssets ?? [] as $child_asset) {
            $child_job = ServiceJob::firstOrCreate(
                [
                    'asset_id' => $child_asset->id,
                    'service_order_id' => $parent_job->service_order_id,
                ],
                [
                    'outcome' => ServiceJobOutcomes::nog_geen_uitkomst->value,
                    'parent_service_job_id' => $parent_job->id,
                ]
            );

            if ($child_job->wasRecentlyCreated) {
                $created[] = $child_job->setRelation('asset', $child_asset);

                continue;
            }

            if ($child_job->parent_service_job_id === null) {
                $child_job->update(['parent_service_job_id' => $parent_job->id]);
            }
        }

        return $created;
    }

    /**
     * The same for a whole werkbon at once, fetching the machines behind those
     * keuringen in one go instead of once per keuring.
     *
     * Hand it every keuring of the werkbon, not one at a time: a machine that
     * sits on the werkbon in its own right keeps the keuring it already has and
     * is adopted by its bundle, rather than being booked a second time.
     *
     * @param  iterable<int, ServiceJob>  $parent_jobs
     * @return array<int, ServiceJob>
     */
    public function createForAll(iterable $parent_jobs): array
    {
        return (new Collection($parent_jobs))
            ->loadMissing(self::PART_RELATIONS)
            ->flatMap(fn (ServiceJob $parent_job) => $this->createFor($parent_job))
            ->all();
    }
}
