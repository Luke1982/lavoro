<?php

namespace App\Domain\Signals\Integrations;

use App\Domain\Signals\BaseSignal;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer, material or ledger account here was linked to its counterpart in
 * a bookkeeping system: recognised there, taken over from there, made there by
 * Lavoro, or found there again after it had gone. From then on the two are kept
 * in step, so it is worth knowing when that started and with which record there.
 *
 * Carries the id there rather than only a sentence, so a record can be traced to
 * its counterpart without parsing Dutch. The id before is the one it was linked
 * to until now, when recognising it replaced a link that was never confirmed
 * there; a link found again was gone until now, as the entry saying so already
 * told.
 */
class RecordLinkedToAdministration extends BaseSignal
{
    public const RECOGNISED = 'recognised';

    public const TAKEN_OVER = 'taken_over';

    public const MADE_THERE = 'made_there';

    public const FOUND_AGAIN = 'found_again';

    public function __construct(
        public Model $record,
        public string $connector,
        public string $system,
        public string $external_id,
        public string $how,
        public ?string $previous_external_id = null,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'integration.record_linked';
    }

    public static function label(): string
    {
        return 'Gekoppeld aan de administratie';
    }

    public function subject(): Model
    {
        return $this->record;
    }

    public function activityCategory(): string
    {
        return $this->how === self::MADE_THERE ? 'export' : 'other';
    }

    public function activityDescription(): ?string
    {
        $there = $this->system . ' (ID ' . $this->external_id . ')';

        return match ($this->how) {
            self::TAKEN_OVER => 'Overgenomen uit ' . $there . '.',
            self::MADE_THERE => 'Aangemaakt in ' . $there . '.',
            self::FOUND_AGAIN => 'Weer gevonden in ' . $there . '; wijzigingen worden weer uitgewisseld.',
            default => 'Herkend in ' . $there . ' en daaraan gekoppeld.',
        };
    }

    public function activityMetadata(): ?array
    {
        return ['connector' => $this->connector, 'external_id' => $this->external_id, 'how' => $this->how];
    }

    public function changes(): array
    {
        return [[
            'field' => 'integration_' . $this->connector,
            'label' => 'Koppeling met ' . $this->system,
            'old_value' => $this->previous_external_id,
            'new_value' => $this->external_id,
            'old_label' => $this->previous_external_id === null ? null : 'ID ' . $this->previous_external_id,
            'new_label' => 'ID ' . $this->external_id,
        ]];
    }
}
