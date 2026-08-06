<?php

namespace App\Domain\Tools\Read;

use App\Domain\Assistant\Contracts\Attachment;
use App\Domain\Assistant\Contracts\TalksToModel;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Image;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Looks at the photos already hanging on a record.
 *
 * A monteur photographs the machines on a job as he goes, and those photos sit
 * on the werkbon doing nothing but proving he was there. They are the same
 * typeplaatjes the catalogue is missing — asking somebody to download and
 * re-upload them to the assistant, one at a time, is the kind of errand nobody
 * runs twice.
 */
class ViewImagesTool implements Tool
{
    /** Four is a job's worth of plates; a whole gallery is a bill, not an answer. */
    private const MOST = 4;

    /** @var array<string, class-string> */
    private const SUBJECTS = [
        'werkbon' => ServiceOrder::class,
        'machine' => Asset::class,
        'storing' => Ticket::class,
        'klant' => Customer::class,
        'product' => Product::class,
    ];

    public static function name(): string
    {
        return 'view_images';
    }

    public function description(): string
    {
        return 'Bekijkt de foto\'s die al bij een record staan, bijvoorbeeld de foto\'s die de monteur '
            . 'bij een werkbon heeft gezet. Gebruik dit als iemand vraagt wat er op de foto\'s staat, '
            . 'of om typeplaatjes van machines af te lezen die al vastgelegd zijn. Je krijgt de foto\'s '
            . 'daarna te zien en leest ze af zoals je een meegestuurde foto afleest.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'subject_type' => [
                    'type' => 'string',
                    'description' => 'Soort record: werkbon, machine, storing, klant of product.',
                ],
                'subject_id' => [
                    'type' => 'integer',
                    'description' => 'Het nummer van dat record.',
                ],
                'skip' => [
                    'type' => 'integer',
                    'description' => 'Sla de eerste zoveel foto\'s over. Staan er meer dan er in één keer '
                        . 'passen, haal de volgende dan op met skip in plaats van opnieuw dezelfde.',
                ],
            ],
            'required' => ['subject_type', 'subject_id'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        /** The record's own scope decides; a photo is as visible as what it hangs on. */
        return true;
    }

    /** Reading plates off somebody else's photos is the same judgement as reading your own. */
    public static function difficulty(): int
    {
        return 6;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public static function availableTo(): array
    {
        return ToolProfile::all();
    }

    public function execute(ToolCall $call): ToolResult
    {
        $type = mb_strtolower(trim((string) $call->stringArgument('subject_type')));

        if (!isset(self::SUBJECTS[$type])) {
            return ToolResult::failed(
                'Onbekend soort record. Kies uit: ' . implode(', ', array_keys(self::SUBJECTS)) . '.'
            );
        }

        /**
         * Refused rather than attempted. Handed to a model that cannot see, the
         * photos arrive as nothing and the answer describes them anyway — the
         * one failure mode that reads exactly like success.
         */
        if (!app(TalksToModel::class)->seesImages()) {
            return ToolResult::failed(
                'Dit gesprek draait op een model zonder beeld. Zeg tegen de gebruiker dat hij het '
                    . 'opnieuw moet vragen met het woord "foto" in de vraag, dan komt er een model bij '
                    . 'dat wel kan kijken. Beschrijf niet wat er op de foto zou staan.'
            );
        }

        $model = self::SUBJECTS[$type];
        $record = $model::query()->visibleTo($call->user)->whereKey($call->integerArgument('subject_id'))->first();

        if ($record === null) {
            return ToolResult::notFound(ucfirst($type) . ' #' . $call->integerArgument('subject_id'));
        }

        $all = $this->everyImageOn($record);
        $skip = max(0, (int) ($call->integerArgument('skip') ?? 0));
        $showing = $all->slice($skip, self::MOST);

        if ($all->isEmpty()) {
            return ToolResult::ok(
                ['images' => [], 'note' => 'Bij dit record staan geen foto\'s.'],
                'Geen foto\'s bij deze ' . $type . '.',
            );
        }

        $attachments = [];
        $rows = [];

        foreach ($showing as $image) {
            $bytes = $this->bytesOf($image->path);

            if ($bytes === null) {
                continue;
            }

            $attachments[] = new Attachment(
                name: $image->name ?: basename($image->path),
                media_type: $this->mediaTypeOf($image->path),
                base64: base64_encode($bytes),
            );

            $rows[] = [
                'image_id' => $image->id,
                'name' => $image->name,
                /** Internal ones are the monteur's own working photos — usually the plates. */
                'internal' => (bool) ($image->pivot->internal ?? false),
            ];
        }

        if ($attachments === []) {
            return ToolResult::failed(
                'De bestanden van deze foto\'s zijn niet te openen. Zeg dat er wel foto\'s bij het '
                    . 'record staan maar dat ze niet ingelezen konden worden.'
            );
        }

        $left = max(0, $all->count() - $skip - count($rows));

        return ToolResult::ok(
            [
                'images' => $rows,
                'total' => $all->count(),
                'shown' => count($rows),
                'note' => 'Je krijgt deze foto\'s hierna te zien. Lees ze af zoals een meegestuurde foto '
                    . 'en meld met report_findings wat je eruit haalt, met percentages.'
                    . ($left > 0
                        ? ' Er staan er nog ' . $left . '; haal die op met skip=' . ($skip + count($rows)) . '.'
                        : ''),
            ],
            count($rows) . ' van de ' . $all->count() . ' foto(\'s) opgehaald.',
        )->showing($attachments);
    }

    /**
     * Every photo on the record, the customer-facing ones and the internal ones.
     *
     * images() hides anything marked internal, because that relation exists to
     * decide what a customer may see on a PDF. The monteur's own photos live on
     * the other side of that flag — and those are the ones with the typeplaatje
     * in them, so reading only the public half was reading the wrong half.
     *
     * @return Collection<int, Image>
     */
    private function everyImageOn(object $record): Collection
    {
        $images = $record->images()->get();

        if (method_exists($record, 'internalImages')) {
            $images = $images->concat($record->internalImages()->get());
        }

        return $images->sortBy('id')->values();
    }

    private function bytesOf(string $path): ?string
    {
        foreach (['public', 'local'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->get($path);
            }
        }

        return null;
    }

    private function mediaTypeOf(string $path): string
    {
        return match (mb_strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }
}
