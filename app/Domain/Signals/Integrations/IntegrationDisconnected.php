<?php

namespace App\Domain\Signals\Integrations;

use App\Domain\Signals\BaseSignal;
use App\Models\IntegrationConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * A connection with a bookkeeping system was removed. Its links go with it, and
 * from then on nothing of the records it linked is exchanged with that system.
 * Raised once, just before it goes, and told on every record it linked: their
 * last word on it was "Gekoppeld aan …", which would otherwise stay true on
 * their timeline forever.
 */
class IntegrationDisconnected extends BaseSignal
{
    /** @param  array<int, Model>  $linked  the records here it linked, as they were just before */
    public function __construct(
        public IntegrationConnection $connection,
        public string $system,
        public array $linked,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'integration.disconnected';
    }

    public static function label(): string
    {
        return 'Koppeling verwijderd';
    }

    public function subject(): Model
    {
        return $this->connection;
    }

    public function activityContext(): array
    {
        return $this->linked;
    }

    public function activityCategory(): string
    {
        return 'status';
    }

    public function activityDescription(): ?string
    {
        return 'Koppeling met ' . $this->system . ' verwijderd. '
            . 'Wijzigingen worden niet meer met ' . $this->system . ' uitgewisseld.';
    }

    public function activityMetadata(): ?array
    {
        return ['connector' => $this->connection->connector];
    }
}
