<?php

namespace App\Domain\Signals\Attachments;

use App\Models\ServiceCheckInstance;
use Illuminate\Database\Eloquent\Model;

/**
 * A photo, a document or a remark is told on the record it hangs off. A keurpunt
 * is the one such record without a timeline of its own, so what happens to one is
 * told on the werkbon its keuring is for, as its answers are
 * (ServiceCheckResultChanged), and on the machine it was done on: a remark or a
 * photo on a keurpunt is about that machine's state. The sentence says which
 * keurpunt, and the machine's timeline names the keuring and links its werkbon.
 *
 * @property Model $record
 */
trait ReachesTheRecordsTimeline
{
    public function subject(): Model
    {
        return $this->checkpoint()?->serviceJob?->serviceOrder ?? $this->record;
    }

    public function activityContext(): array
    {
        return array_values(array_filter([$this->checkpoint()?->serviceJob?->asset]));
    }

    /** The keurpunt and its machine as the sentence names them, or nothing when the record has a timeline itself. */
    protected function atCheckpoint(): string
    {
        $checkpoint = $this->checkpoint();

        if ($checkpoint === null) {
            return '';
        }

        $machine = $checkpoint->serviceJob?->asset?->historyLabel();

        return ' bij keurpunt "' . ($checkpoint->serviceCheck?->name ?? 'onbekend') . '"'
            . ($machine ? ' van ' . $machine : '');
    }

    /**
     * About a keurpunt, the entry also carries the keuring by name and its werkbon,
     * which the machine's page links to, the way a keuring's own entries do.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    protected function withCheckpoint(array $metadata = []): ?array
    {
        $service_job = $this->checkpoint()?->serviceJob;

        if ($service_job !== null) {
            $metadata = [...array_filter([
                'subject_label' => $service_job->historyLabel(),
                'service_order_id' => $service_job->serviceOrder?->getKey(),
                'service_order_number' => $service_job->serviceOrder?->number,
            ], fn ($value) => $value !== null), ...$metadata];
        }

        return $metadata === [] ? null : $metadata;
    }

    private function checkpoint(): ?ServiceCheckInstance
    {
        return $this->record instanceof ServiceCheckInstance ? $this->record : null;
    }
}
