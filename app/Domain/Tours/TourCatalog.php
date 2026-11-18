<?php

namespace App\Domain\Tours;

use App\Models\TourProgress;
use App\Models\User;

/**
 * The rondleidingen, read from the same storyboards the screen plays.
 *
 * They live as JSON next to the code that shows them, so the server never keeps
 * a second copy: the assistant offers chapters from these files, the audio is
 * spoken from these files, and the seen-state is checked against these files.
 */
class TourCatalog
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $tours = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function tours(): array
    {
        if ($this->tours !== null) {
            return $this->tours;
        }

        $this->tours = [];
        foreach (glob(resource_path('js/Tours/storyboards/*.json')) ?: [] as $path) {
            $tour = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $this->tours[$tour['id']] = $tour;
        }

        return $this->tours;
    }

    /** @return array<int, string> */
    public function ids(): array
    {
        return array_keys($this->tours());
    }

    /** @return array<string, mixed>|null */
    public function tour(string $id): ?array
    {
        return $this->tours()[$id] ?? null;
    }

    /**
     * The rondleidingen the assistant may offer. One that lives in a window of
     * its own, such as the map, says `"assistant": false`: started from a chat
     * it would open in the main window instead.
     *
     * @return array<int, string>
     */
    public function offeredIds(): array
    {
        return array_keys(array_filter($this->tours(), fn (array $tour) => $tour['assistant'] ?? true));
    }

    /** @return array<int, string> */
    public function chapterIds(string $tour): array
    {
        return array_column($this->tour($tour)['chapters'] ?? [], 'id');
    }

    /** @return array<string, mixed>|null */
    public function chapter(string $tour, string $chapter): ?array
    {
        foreach ($this->tour($tour)['chapters'] ?? [] as $candidate) {
            if ($candidate['id'] === $chapter) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The chapters that have a recording under public/tours, so the screen only
     * asks for the ones that are there instead of collecting a 404 per chapter.
     *
     * @return array<int, string>
     */
    public function recordedChapters(string $tour): array
    {
        $folder = config('tours.output') . '/' . $tour;

        return collect(glob($folder . '/*.json') ?: [])
            ->map(fn (string $path) => pathinfo($path, PATHINFO_FILENAME))
            ->filter(fn (string $chapter) => is_file($folder . '/' . $chapter . '.mp3'))
            ->intersect($this->chapterIds($tour))
            ->values()
            ->all();
    }

    /**
     * The chapters of a rondleiding this person may be offered, each with only
     * the steps that apply to them. Whether a chapter is for a phone or a large
     * screen is left in, for the screen to decide.
     *
     * @return array<int, array<string, mixed>>
     */
    public function chaptersFor(User $user, string $tour): array
    {
        $allowed = fn (array $item) => $user->can('watch', [TourProgress::class, $this->rightsOf($item['requires'] ?? [])]);

        if ($this->tour($tour) === null || !$allowed($this->tour($tour))) {
            return [];
        }

        return collect($this->tour($tour)['chapters'])
            ->filter($allowed)
            ->map(fn (array $chapter) => [...$chapter, 'steps' => array_values(array_filter($chapter['steps'], $allowed))])
            ->filter(fn (array $chapter) => $chapter['steps'] !== [])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $requires
     * @return array<string, array<int, string>>
     */
    private function rightsOf(array $requires): array
    {
        return array_intersect_key($requires, array_flip(['permissions', 'anyPermissions', 'modules']));
    }
}
