<?php

namespace App\Domain\Assistant;

use App\Domain\Assistant\Contracts\Attachment;
use App\Domain\Signals\Attachments\ImageAttached;
use App\Domain\Signals\Signals;
use App\Models\Asset;
use App\Models\Event;
use App\Models\Image;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * The photos that came with a conversation, parked until somebody decides.
 *
 * A typeplaatje sent to the assistant is gone the moment the question is
 * answered, and the person on the ladder photographed it for a reason. So the
 * photos wait in a temporary corner, and when the conversation ends the person
 * chooses: into their storage — attached to whatever record the conversation
 * was actually about — or thrown away. Undecided ones are pruned after a few
 * days rather than kept by default, because storage is theirs and counts
 * against their limits.
 */
class ConversationPhotos
{
    /**
     * Where a kept photo can land, best home first. The conversation's own
     * notes say which of these it settled; a photo of a machine belongs on the
     * machine, not on the werkbon that happened to mention it.
     *
     * Klant is not in the list: customers do not take images in this
     * application.
     *
     * @var array<string, class-string>
     */
    private const HOMES = [
        'machine' => Asset::class,
        'werkbon' => ServiceOrder::class,
        'product' => Product::class,
        'storing' => Ticket::class,
        'afspraak' => Event::class,
    ];

    public function stash(string $conversation, array $attachments): void
    {
        foreach ($attachments as $index => $attachment) {
            if (!$attachment instanceof Attachment) {
                continue;
            }

            $extension = match ($attachment->media_type) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                default => 'jpg',
            };

            Storage::disk('local')->put(
                $this->folder($conversation) . '/foto-' . ($index + 1) . '-' . uniqid() . '.' . $extension,
                base64_decode($attachment->base64),
            );
        }
    }

    /**
     * Into their storage, attached to the record the conversation settled.
     *
     * @return array{count: int, home: string}|null Null when there is nothing to keep.
     */
    public function keep(string $conversation, User $user): ?array
    {
        $files = Storage::disk('local')->files($this->folder($conversation));

        if ($files === []) {
            return null;
        }

        $home = $this->homeFor($conversation, $user);

        if ($home === null) {
            return ['count' => 0, 'home' => ''];
        }

        [$record, $noun] = $home;

        $model_name = strtolower(class_basename($record));
        $destination = 'uploaded/' . $model_name . '/' . $record->id . '/';
        $kept = [];

        foreach ($files as $file) {
            $name = 'assistent-' . basename($file);

            /** The same convention as a hand upload, so nothing downstream can tell them apart. */
            Storage::disk('public')->put($destination . $name, (string) Storage::disk('local')->get($file));

            $image = Image::create([
                'name' => $name,
                'path' => $destination . $name,
            ]);

            $record->images()->attach($image->id);
            $kept[] = $image;
        }

        Storage::disk('local')->deleteDirectory($this->folder($conversation));

        Signals::dispatch(new ImageAttached($record, count($kept), $kept[0]->path, $kept[0]->id));

        return ['count' => count($kept), 'home' => $noun . ' #' . $record->id];
    }

    /** Whether this conversation has photos parked — which also means it HAD photos. */
    public function has(string $conversation): bool
    {
        return Storage::disk('local')->files($this->folder($conversation)) !== [];
    }

    /**
     * The parked photos as attachments again, so a follow-up can look at them.
     *
     * @return array<int, Attachment>
     */
    public function parked(string $conversation): array
    {
        $disk = Storage::disk('local');

        return collect($disk->files($this->folder($conversation)))
            ->map(function (string $file) use ($disk) {
                $extension = pathinfo($file, PATHINFO_EXTENSION);

                return new Attachment(
                    name: basename($file),
                    media_type: $extension === 'jpg' ? 'image/jpeg' : 'image/' . $extension,
                    base64: base64_encode((string) $disk->get($file)),
                );
            })
            ->values()
            ->all();
    }

    public function discard(string $conversation): void
    {
        Storage::disk('local')->deleteDirectory($this->folder($conversation));
    }

    /** Parked photos nobody decided about, gone after their few days of grace. */
    public function pruneOlderThan(int $days): int
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subDays($days)->timestamp;
        $pruned = 0;

        foreach ($disk->directories('assistant-photos') as $folder) {
            $newest = collect($disk->files($folder))->map(fn (string $file) => $disk->lastModified($file))->max();

            if ($newest !== null && $newest < $cutoff) {
                $disk->deleteDirectory($folder);
                $pruned++;
            }
        }

        return $pruned;
    }

    /** @return array{0: Model, 1: string}|null */
    private function homeFor(string $conversation, User $user): ?array
    {
        $facts = app(ConversationFacts::class)->for($conversation, $user);

        foreach (self::HOMES as $noun => $model) {
            $id = $facts[$noun]['id'] ?? null;

            if ($id === null) {
                continue;
            }

            $record = $model::find($id);

            if ($record !== null) {
                return [$record, $noun];
            }
        }

        return null;
    }

    private function folder(string $conversation): string
    {
        return 'assistant-photos/' . $conversation;
    }
}
