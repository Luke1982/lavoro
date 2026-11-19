<?php

namespace App\Models\Traits;

use App\Models\Integrationable;
use App\Models\IntegrationConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A record that can be linked to its counterpart in an outside system.
 *
 * @mixin Model
 */
trait HasIntegrations
{
    public function integrationLinks(): MorphMany
    {
        return $this->morphMany(Integrationable::class, 'integrationable');
    }

    public function integrationLink(IntegrationConnection $connection): ?Integrationable
    {
        return $this->integrationLinks()->where('integration_connection_id', $connection->id)->first();
    }

    /** Its id there; none when it was removed there, so nothing is sent that would be refused. */
    public function externalId(IntegrationConnection $connection): ?string
    {
        $link = $this->integrationLink($connection);

        return $link && !$link->missing_at ? $link->external_id : null;
    }

    /**
     * Records with no confirmed link to this system yet. A link that was never
     * confirmed -- a made-up SnelStart id from before -- does not count: such a
     * customer can still be recognised as the one that is really there.
     */
    public function scopeNotLinkedTo(Builder $query, IntegrationConnection $connection): Builder
    {
        return $query->whereDoesntHave(
            'integrationLinks',
            fn (Builder $links) => $links->where('integration_connection_id', $connection->id)->whereNotNull('confirmed_at'),
        );
    }
}
