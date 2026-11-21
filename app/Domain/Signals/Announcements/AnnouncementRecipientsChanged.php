<?php

namespace App\Domain\Signals\Announcements;

use App\Domain\Signals\BaseSignal;
use App\Domain\Signals\Users\NameListChange;
use App\Models\InternalAnnouncement;
use Illuminate\Database\Eloquent\Model;

/**
 * The people an announcement is for changed: it was sent, or the audience was
 * changed afterwards. Someone who already acknowledged stays on it, so whoever
 * is taken off here had not read it yet.
 */
class AnnouncementRecipientsChanged extends BaseSignal
{
    /** Beyond this many names a sentence is a list nobody reads; the change row keeps them all. */
    private const NAMES_IN_A_SENTENCE = 5;

    public function __construct(public InternalAnnouncement $announcement, public NameListChange $recipients)
    {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'announcement.recipients_changed';
    }

    public static function label(): string
    {
        return 'Ontvangers van aankondiging gewijzigd';
    }

    public function subject(): Model
    {
        return $this->announcement;
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    public function activityDescription(): ?string
    {
        $parts = array_filter([
            $this->part($this->recipients->added(), 'ontvangen de aankondiging', 'ontvangt de aankondiging'),
            $this->part($this->recipients->removed(), 'niet langer ontvangers', 'niet langer ontvanger'),
        ]);

        return ucfirst(implode('; ', $parts));
    }

    public function changes(): array
    {
        return $this->recipients->deltaChanges('recipients', 'Ontvangers erbij', 'Ontvangers eraf');
    }

    /** @param array<int, string> $names */
    private function part(array $names, string $plural, string $singular): ?string
    {
        return match (true) {
            $names === [] => null,
            count($names) > self::NAMES_IN_A_SENTENCE => count($names) . ' mensen ' . $plural,
            count($names) === 1 => $names[0] . ' ' . $singular,
            default => NameListChange::names($names) . ' ' . $plural,
        };
    }
}
