<?php

namespace App\Domain\Assistant;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The manual of Lavoro itself, split into chapters the assistant can hand over.
 *
 * This is about the application — how a werkbon moves through its fases, where
 * rights are configured — and deliberately not about anybody's data. It is the
 * counterpart of ProductDocumentation, which reads the papers filed against a
 * product: that one answers "what is the refrigerant", this one answers "how do
 * I close a werkbon".
 *
 * Chapters rather than the whole file, because the manual grows with the
 * application and the whole of it would crowd out the question it was fetched
 * to answer. A chapter is one `## ` heading and everything under it, addressed
 * by the slug of its title so the model can ask for it by name.
 */
class ApplicationManual
{
    private const PATH = 'docs/handleiding.md';

    /**
     * Words that carry no topic. A model searches with whatever the user said,
     * and "hoe plan ik een afspraak" must not miss the planning chapter because
     * the word "hoe" happens not to appear in it.
     */
    private const STOP_WORDS = [
        'de', 'het', 'een', 'en', 'of', 'in', 'op', 'aan', 'bij', 'van', 'voor', 'naar',
        'met', 'om', 'te', 'is', 'zijn', 'wordt', 'worden', 'kan', 'kun', 'mag', 'moet',
        'ik', 'je', 'jij', 'u', 'we', 'wij', 'ze', 'zij', 'hoe', 'wat', 'waar', 'wanneer',
        'welke', 'wie', 'dat', 'dit', 'die', 'deze', 'er', 'niet', 'wel', 'ook', 'nog',
    ];

    /**
     * What a hit in a chapter title counts for, against one in its body. The
     * title says what a chapter is about; the body mentions everything else.
     */
    private const TITLE_WEIGHT = 5;

    /** @var Collection<int, array{slug: string, title: string, body: string}>|null */
    private ?Collection $chapters = null;

    public function isAvailable(): bool
    {
        return $this->all()->isNotEmpty();
    }

    /**
     * Every chapter, title and slug only — the table of contents.
     *
     * @return Collection<int, array{slug: string, title: string}>
     */
    public function tableOfContents(): Collection
    {
        return $this->all()->map(fn (array $chapter) => [
            'slug' => $chapter['slug'],
            'title' => $chapter['title'],
        ]);
    }

    /** @return array{slug: string, title: string, body: string}|null */
    public function chapter(string $slug): ?array
    {
        return $this->all()->firstWhere('slug', trim($slug));
    }

    /**
     * The chapters in which a term occurs, the richest hits first.
     *
     * Matched word for word rather than as one literal phrase: "werkbon
     * afsluiten" should find the chapter that says "de werkbon af te sluiten",
     * and as a phrase it never would. Chapters containing every word come
     * first; when no chapter has them all — Dutch conjugates, so "instellen"
     * misses a chapter that says "in te stellen" — the ones matching most of
     * the words stand in rather than nothing at all. Within a group, ranked by
     * how often the words occur.
     *
     * @return Collection<int, array{slug: string, title: string, body: string}>
     */
    public function matching(string $term): Collection
    {
        $words = preg_split('/\s+/', mb_strtolower(trim($term)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_diff($words, self::STOP_WORDS));

        if ($words === []) {
            return collect();
        }

        $weighed = $this->all()
            ->map(fn (array $chapter) => ['chapter' => $chapter] + $this->weigh($chapter, $words))
            ->filter(fn (array $hit) => $hit['words_found'] > 0);

        $complete = $weighed->filter(fn (array $hit) => $hit['words_found'] === count($words));

        return ($complete->isNotEmpty() ? $complete : $weighed)
            ->sortBy([['words_found', 'desc'], ['weight', 'desc']])
            ->pluck('chapter')
            ->values();
    }

    /**
     * How well one chapter matches a set of words: how many of them occur at
     * all, and how often taken together.
     *
     * @param  array{slug: string, title: string, body: string}  $chapter
     * @param  array<int, string>  $words
     * @return array{words_found: int, weight: int}
     */
    private function weigh(array $chapter, array $words): array
    {
        $title = mb_strtolower($chapter['title']);
        $body = mb_strtolower($chapter['body']);
        $found = 0;
        $weight = 0;

        foreach ($words as $word) {
            $occurrences = mb_substr_count($body, $word)
                + mb_substr_count($title, $word) * self::TITLE_WEIGHT;

            if ($occurrences > 0) {
                $found++;
                $weight += $occurrences;
            }
        }

        return ['words_found' => $found, 'weight' => $weight];
    }

    /** @return Collection<int, array{slug: string, title: string, body: string}> */
    private function all(): Collection
    {
        if ($this->chapters !== null) {
            return $this->chapters;
        }

        $path = base_path(self::PATH);

        if (!is_readable($path)) {
            return $this->chapters = collect();
        }

        return $this->chapters = $this->split((string) file_get_contents($path));
    }

    /**
     * Cuts the markdown into chapters on its `## ` headings.
     *
     * Whatever stands above the first heading becomes the opening chapter, so
     * an introduction written at the top does not silently vanish from what the
     * assistant can read. The document's own `# ` title line is dropped from
     * it: the reader gets a title with every chapter already.
     *
     * @return Collection<int, array{slug: string, title: string, body: string}>
     */
    private function split(string $markdown): Collection
    {
        $sections = preg_split('/^##[ \t]+/m', $markdown);
        $preamble = array_shift($sections) ?? '';

        return collect($sections)
            ->map(function (string $section) {
                [$title, $body] = array_pad(explode("\n", $section, 2), 2, '');

                return $this->chapterFrom(trim($title), $body);
            })
            ->prepend($this->chapterFrom('Inleiding', (string) preg_replace('/^#[ \t].*$/m', '', $preamble)))
            ->filter()
            ->values();
    }

    /** @return array{slug: string, title: string, body: string}|null */
    private function chapterFrom(string $title, string $body): ?array
    {
        $text = trim($body);

        if ($title === '' || $text === '') {
            return null;
        }

        return [
            'slug' => Str::slug($title),
            'title' => $title,
            'body' => $text,
        ];
    }
}
