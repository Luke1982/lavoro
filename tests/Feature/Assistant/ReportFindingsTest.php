<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\Contracts\ModelReply;
use App\Domain\Assistant\Contracts\ModelToolCall;
use App\Domain\Assistant\Contracts\StopReason;
use App\Domain\Assistant\Contracts\TalksToModel;
use App\Domain\Assistant\Contracts\TokenUsage;
use App\Domain\Assistant\ModelPicker;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A third answer between certainty and silence.
 *
 * Told never to guess, the assistant went quiet about a photo it had read
 * perfectly well a week earlier — "het merk is te wazig" about a plate that
 * plainly says TOSOT. Refusing is not the opposite of fabricating: the person
 * holding the camera can confirm a hunch in a second and can do nothing at all
 * with a refusal.
 */
class ReportFindingsTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private function report(array $arguments): ToolResult
    {
        return app(ToolExecutor::class)->run(new ToolCall(
            'report_findings',
            $arguments,
            $this->userWith('assistant.use'),
        ));
    }

    public function test_each_finding_keeps_its_own_percentage(): void
    {
        $result = $this->report(['findings' => [
            ['field' => 'merk', 'value' => 'Tosot', 'confidence' => 80, 'basis' => 'foto'],
            ['field' => 'model', 'value' => 'FTS-24R', 'confidence' => 45, 'basis' => 'internet'],
        ]]);

        $this->assertFalse($result->is_error);
        $this->assertSame('Tosot', $result->content['findings'][0]['value']);
        $this->assertSame(80, $result->content['findings'][0]['confidence']);
        $this->assertSame(45, $result->content['findings'][1]['confidence']);
        $this->assertSame('internet', $result->content['findings'][1]['basis']);
    }

    /** Enthusiasm is not an error, but it is not 120 per cent either. */
    public function test_a_percentage_outside_the_scale_is_brought_back_onto_it(): void
    {
        $result = $this->report(['findings' => [
            ['field' => 'merk', 'value' => 'Tosot', 'confidence' => 140],
            ['field' => 'model', 'value' => 'X', 'confidence' => -20],
        ]]);

        $this->assertSame(100, $result->content['findings'][0]['confidence']);
        $this->assertSame(0, $result->content['findings'][1]['confidence']);
    }

    public function test_what_could_not_be_read_travels_too(): void
    {
        $result = $this->report([
            'findings' => [['field' => 'merk', 'value' => 'Tosot', 'confidence' => 70]],
            'unreadable' => ['serienummer', '  ', 'modelcode'],
        ]);

        $this->assertSame(['serienummer', 'modelcode'], $result->content['unreadable']);
    }

    public function test_nothing_worth_reporting_is_refused(): void
    {
        $this->assertTrue($this->report(['findings' => []])->is_error);
        $this->assertTrue($this->report(['findings' => [['field' => '', 'value' => '', 'confidence' => 90]]])->is_error);
    }

    /**
     * Through the endpoint, because the tool passing its own tests proves
     * nothing about the box ever seeing the bars — that join is where this
     * assistant has hidden most of its bugs.
     */
    public function test_the_findings_reach_the_answer(): void
    {
        $this->app->bind(ModelPicker::class, fn () => ModelPicker::fixed(new FindingsModel));

        $answer = $this->actingAs($this->userWith('assistant.use'))->postJson('/assistant/ask', [
            'question' => 'Wat is dit voor apparaat?',
        ])->assertOk()->json();

        $this->assertCount(1, $answer['findings'], 'the bars never left the server');
        $this->assertSame('merk', $answer['findings'][0]['findings'][0]['field']);
        $this->assertSame('Tosot', $answer['findings'][0]['findings'][0]['value']);
        $this->assertSame(70, $answer['findings'][0]['findings'][0]['confidence']);
        $this->assertSame(['serienummer'], $answer['findings'][0]['unreadable']);
    }
}

/** Reports one finding, then answers — the smallest photo conversation there is. */
class FindingsModel implements TalksToModel
{
    private bool $reported = false;

    public function readsDocuments(): bool
    {
        return false;
    }

    public function send(array $turns, array $tools, string $system): ModelReply
    {
        $reply = fn (array $texts, array $calls, $stop) => new ModelReply(
            texts: $texts,
            tool_calls: $calls,
            usage: new TokenUsage(1, 1, 0, 0),
            stop_reason: $stop,
            model: 'findings-fake',
            raw: null,
        );

        if (!$this->reported) {
            $this->reported = true;

            return $reply([], [new ModelToolCall(
                id: 'toolu_findings',
                name: 'report_findings',
                arguments: [
                    'findings' => [['field' => 'merk', 'value' => 'Tosot', 'confidence' => 70, 'basis' => 'foto']],
                    'unreadable' => ['serienummer'],
                ],
            )], StopReason::wants_tools);
        }

        return $reply(['Waarschijnlijk een Tosot.'], [], StopReason::finished);
    }
}
