<?php

namespace Tests\Feature\Assistant;

use App\Domain\Tools\Read\AskWhichOneTool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolResult;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Putting a choice to somebody instead of listing it at them.
 *
 * Three customers in Ede with "dijk" in the name is the ordinary case, and prose
 * left two bad ways out: the links go to the record, so following one leaves the
 * conversation that needed the answer, and words do not reliably land — "eerste"
 * was met with a request to say which one.
 */
class ChoiceToolTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private function offer(array $arguments): ToolResult
    {
        return app(ToolExecutor::class)->run(
            new ToolCall('ask_which_one', $arguments, $this->userWith('serviceorder.read'))
        );
    }

    private function threeCustomers(): array
    {
        return [
            ['label' => 'J. van Reemst van Dijk — Hakselseweg 55A, Ede', 'reference' => 'klant #1037', 'link' => '/customers/1037'],
            ['label' => 'P. Dijkema — Boedelhof 22, Ede', 'reference' => 'klant #1450', 'link' => '/customers/1450'],
            ['label' => 'S. van Dijk — Kerkweg 64, Ede', 'reference' => 'klant #1732', 'link' => '/customers/1732'],
        ];
    }

    public function test_a_choice_comes_back_as_options_the_box_can_render(): void
    {
        $content = $this->offer([
            'question' => 'Welke klant bedoel je?',
            'options' => $this->threeCustomers(),
        ])->content;

        $this->assertSame('keuze_nodig', $content['status']);
        $this->assertCount(3, $content['options']);
        $this->assertSame('klant #1037', $content['options'][0]['reference']);
        $this->assertSame('/customers/1037', $content['options'][0]['link']);
    }

    /**
     * An option is rendered as something clickable, so a value from a model that
     * could name any host at all would be a link into somebody else's site wearing
     * this application's clothes.
     */
    public function test_a_link_that_leaves_the_application_is_dropped(): void
    {
        $content = $this->offer([
            'question' => 'Welke?',
            'options' => [
                ['label' => 'Eerste', 'reference' => 'klant #1', 'link' => 'https://evil.test/steal'],
                ['label' => 'Tweede', 'reference' => 'klant #2', 'link' => '//evil.test/steal'],
                ['label' => 'Derde', 'reference' => 'klant #3', 'link' => 'javascript:alert(1)'],
                ['label' => 'Vierde', 'reference' => 'klant #4', 'link' => '/customers/4'],
            ],
        ])->content;

        $this->assertNull($content['options'][0]['link']);
        $this->assertNull($content['options'][1]['link']);
        $this->assertNull($content['options'][2]['link']);
        $this->assertSame('/customers/4', $content['options'][3]['link']);
    }

    /**
     * Asked to pick between one thing somebody can only agree, which is not a
     * question worth putting.
     */
    public function test_one_option_is_not_a_choice(): void
    {
        $result = $this->offer([
            'question' => 'Welke klant bedoel je?',
            'options' => [['label' => 'De enige', 'reference' => 'klant #1']],
        ]);

        $this->assertTrue($result->is_error);
    }

    public function test_options_without_a_label_or_a_reference_are_dropped(): void
    {
        $result = $this->offer([
            'question' => 'Welke?',
            'options' => [
                ['label' => 'Zonder verwijzing'],
                ['reference' => 'klant #2'],
                'niet eens een object',
            ],
        ]);

        $this->assertTrue($result->is_error, 'a choice was offered with nothing to choose between');
    }

    public function test_it_does_not_offer_a_shelf_of_options(): void
    {
        $options = [];

        foreach (range(1, 30) as $n) {
            $options[] = ['label' => 'Klant ' . $n, 'reference' => 'klant #' . $n];
        }

        $this->assertLessThanOrEqual(8, count($this->offer(['question' => 'Welke?', 'options' => $options])->content['options']));
    }

    /**
     * A handful of matches offers the choice itself, because the tool for asking is
     * used about half the time and half is not a mechanism.
     */
    public function test_a_handful_of_customers_offers_its_own_choice(): void
    {
        foreach (['J. van Reemst van Dijk', 'P. Dijkema', 'S. van Dijk'] as $name) {
            Customer::factory()->create(['name' => $name, 'city' => 'Ede']);
        }

        $content = app(ToolExecutor::class)->run(new ToolCall(
            'find_customer',
            ['query' => 'dijk', 'city' => 'Ede'],
            $this->userWith('customer.read'),
        ))->content;

        $this->assertCount(3, $content['choice']['options']);
        $this->assertStringContainsString('J. van Reemst van Dijk', $content['choice']['options'][0]['label']);
        $this->assertSame('/customers/' . Customer::where('name', 'J. van Reemst van Dijk')->value('id'), $content['choice']['options'][0]['link']);
    }

    /**
     * Handed the whole list, the narrowing happened out of sight: twenty-five rows
     * went over, three in Ede were picked out in prose, and nothing could turn
     * those into buttons. The rows are withheld so the narrowing has to come back
     * through the tool.
     */
    public function test_too_many_customers_withholds_the_rows_and_says_where_they_are(): void
    {
        foreach (range(1, 12) as $n) {
            Customer::factory()->create(['name' => 'Van Dijk ' . $n, 'city' => $n <= 3 ? 'Ede' : 'Plaats ' . $n]);
        }

        $content = app(ToolExecutor::class)->run(new ToolCall(
            'find_customer',
            ['query' => 'dijk'],
            $this->userWith('customer.read'),
        ))->content;

        $this->assertSame([], $content['customers'], 'the rows went over and could be filtered out of sight');
        $this->assertSame(12, $content['matches']);
        $this->assertSame(3, $content['per_place']['Ede']);
    }

    public function test_a_choice_changes_nothing_so_it_needs_no_confirmation(): void
    {
        $this->assertFalse(app(AskWhichOneTool::class)->requiresConfirmation());
    }
}
