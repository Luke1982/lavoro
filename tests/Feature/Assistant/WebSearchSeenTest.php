<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\Contracts\ModelReply;
use App\Domain\Assistant\Contracts\StopReason;
use App\Domain\Assistant\Contracts\TalksToModel;
use App\Domain\Assistant\Contracts\TokenUsage;
use App\Domain\Assistant\ModelPicker;
use App\Models\Brand;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * What the supplier's web search read counts as seen.
 *
 * A model number the model just read on a spec sheet is absent from every tool
 * result — exactly like a fabricated one. Without this wire the invented-record
 * check flags an answer that did its homework, and a warning that cries wolf
 * teaches people to scroll past the one that matters.
 */
class WebSearchSeenTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private function askedWith(array $searched): array
    {
        SearchedModel::$searched = $searched;

        $this->app->bind(ModelPicker::class, fn () => new ModelPicker(
            fn () => new SearchedModel
        ));

        Product::factory()->create([
            'brand_id' => Brand::factory()->create(['name' => 'Mitsubishi'])->id,
            'model' => 'SRK 25 ZS-W',
        ]);

        return $this->actingAs($this->userWith('assistant.use'))->postJson('/assistant/ask', [
            'question' => 'Wat zijn de specificaties van de SRK 71 ZR?',
        ])->json();
    }

    public function test_a_model_number_read_on_the_internet_is_not_an_invention(): void
    {
        $answer = $this->askedWith(['Mitsubishi SRK 71 ZR spec sheet (https://mhi.example/srk71)']);

        $this->assertSame([], $answer['unverified'], 'an answer that did its homework was flagged as invented');
    }

    /** And without the search, the same sentence is still caught. */
    public function test_the_same_number_from_nowhere_is_still_flagged(): void
    {
        $answer = $this->askedWith([]);

        $this->assertNotEmpty($answer['unverified'], 'a model number from nowhere went unflagged');
    }
}

/** Answers with a model number, having "searched" whatever the test says it did. */
class SearchedModel implements TalksToModel
{
    /** @var array<int, string> */
    public static array $searched = [];

    public function seesImages(): bool
    {
        return true;
    }

    public function readsDocuments(): bool
    {
        return false;
    }

    public function send(array $turns, array $tools, string $system): ModelReply
    {
        return new ModelReply(
            texts: ['De Mitsubishi SRK 71 ZR levert 7,1 kW.'],
            tool_calls: [],
            usage: new TokenUsage(1, 1, 0, 0),
            stop_reason: StopReason::finished,
            model: 'searched-fake',
            raw: null,
            searched: self::$searched,
        );
    }
}
