<?php

namespace App\Domain\Signals\Integrations;

use App\Domain\Signals\BaseSignal;
use Illuminate\Database\Eloquent\Model;

/**
 * The counterpart of a record here is no longer in the bookkeeping system: it
 * was removed there. The record here stays as it is, and from now on nothing of
 * it is exchanged with that system, which is what a person wonders about when a
 * change no longer arrives there.
 */
class RecordGoneFromAdministration extends BaseSignal
{
    public function __construct(
        public Model $record,
        public string $connector,
        public string $system,
        public string $external_id,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'integration.record_gone';
    }

    public static function label(): string
    {
        return 'Verdwenen uit de administratie';
    }

    public function subject(): Model
    {
        return $this->record;
    }

    public function activityCategory(): string
    {
        return 'warning';
    }

    public function activityDescription(): ?string
    {
        return 'Niet meer gevonden in ' . $this->system . ' (ID ' . $this->external_id . '): daar verwijderd. '
            . 'Wijzigingen worden niet meer met ' . $this->system . ' uitgewisseld.';
    }

    public function activityMetadata(): ?array
    {
        return ['connector' => $this->connector, 'external_id' => $this->external_id];
    }

    public function changes(): array
    {
        return [[
            'field' => 'integration_' . $this->connector,
            'label' => 'Koppeling met ' . $this->system,
            'old_value' => $this->external_id,
            'new_value' => null,
            'old_label' => 'ID ' . $this->external_id,
            'new_label' => null,
        ]];
    }
}
