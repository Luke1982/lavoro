<?php

namespace App\Models;

use App\Domain\Signals\Settings\StandardEmailAttachmentsChanged;
use App\Domain\Signals\Settings\StandardEmailTriggersChanged;
use App\Domain\Signals\Signals;
use App\Domain\Signals\Users\NameListChange;
use App\Enums\EventTrigger;
use App\Enums\StandardEmailTriggerType;
use App\Models\Traits\HasActivities;
use App\Models\Traits\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StandardEmail extends Model
{
    use HasActivities;
    use RecordsHistory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'subject',
        'body',
    ];

    protected array $activity_labels = [
        'name' => 'Naam',
        'subject' => 'Onderwerp',
        'body' => 'Tekst',
    ];

    protected array $activity_html = [
        'body',
    ];

    public function triggers(): HasMany
    {
        return $this->hasMany(StandardEmailTrigger::class);
    }

    public function standardAttachments(): BelongsToMany
    {
        return $this->belongsToMany(StandardAttachment::class, 'standard_email_standard_attachment')
            ->withTimestamps();
    }

    /**
     * Replaces the moments this e-mail goes out on and says which came and went,
     * by what they are called on the screen: "Afspraak aangemaakt (automatisch
     * versturen)".
     *
     * @param  array<int, array{trigger: string, trigger_type: string}>  $triggers
     */
    public function replaceTriggers(array $triggers): void
    {
        $before = $this->describedTriggers();

        $this->triggers()->delete();
        $this->triggers()->createMany($triggers);

        $change = new NameListChange($before, $this->describedTriggers());

        if (!$change->isEmpty()) {
            Signals::dispatch(new StandardEmailTriggersChanged($this, $change));
        }
    }

    /**
     * Gives the e-mail exactly these standard attachments and says which came
     * and went.
     *
     * @param  array<int, int|string>  $attachment_ids
     */
    public function syncStandardAttachments(array $attachment_ids): void
    {
        $names = fn () => $this->standardAttachments()
            ->pluck('standard_attachments.name', 'standard_attachments.id')
            ->all();
        $before = $names();

        $this->standardAttachments()->sync($attachment_ids);

        $change = new NameListChange($before, $names());

        if (!$change->isEmpty()) {
            Signals::dispatch(new StandardEmailAttachmentsChanged($this, $change));
        }
    }

    /**
     * Keyed by what the trigger is rather than by its row: the rows are thrown
     * away and made again on every save, so their ids say nothing.
     *
     * @return array<string, string>
     */
    private function describedTriggers(): array
    {
        return $this->triggers()->get()
            ->mapWithKeys(fn (StandardEmailTrigger $trigger) => [
                $trigger->trigger . ':' . $trigger->trigger_type => self::describeTrigger($trigger),
            ])
            ->all();
    }

    private static function describeTrigger(StandardEmailTrigger $trigger): string
    {
        $moment = collect(EventTrigger::cases())->firstWhere('name', $trigger->trigger)?->value ?? $trigger->trigger;
        $way = collect(StandardEmailTriggerType::cases())->firstWhere('name', $trigger->trigger_type)?->value
            ?? $trigger->trigger_type;

        return $moment . ' (' . mb_strtolower($way) . ')';
    }
}
