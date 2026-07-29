<?php

namespace Tests\Feature\Assistant;

use App\Domain\Tools\ToolCall;
use App\Models\User;
use Tests\TestCase;

/**
 * Arguments arrive from a language model rather than from a form, so they are
 * suggestions about shape rather than guarantees. A model that sends 42 where a
 * list was asked for should narrow the search as intended, not be read as a list
 * of digits and quietly match nothing.
 */
class ToolArgumentTest extends TestCase
{
    private function toolCall(array $arguments): ToolCall
    {
        return new ToolCall('search_service_orders', $arguments, User::factory()->make());
    }

    public function test_a_list_of_ids_comes_back_as_given(): void
    {
        $this->assertSame([4, 8, 15], $this->toolCall(['customer_ids' => [4, 8, 15]])->integerListArgument('customer_ids'));
    }

    public function test_a_single_id_is_treated_as_a_list_of_one(): void
    {
        $this->assertSame([42], $this->toolCall(['customer_ids' => 42])->integerListArgument('customer_ids'));
    }

    public function test_ids_sent_as_text_still_count(): void
    {
        $this->assertSame([7, 9], $this->toolCall(['customer_ids' => ['7', '9']])->integerListArgument('customer_ids'));
    }

    public function test_repeats_are_dropped_so_one_customer_is_not_searched_twice(): void
    {
        $this->assertSame([3, 5], $this->toolCall(['customer_ids' => [3, 5, 3, 5]])->integerListArgument('customer_ids'));
    }

    /**
     * Nothing usable must mean "no filter asked for" rather than a filter on id
     * zero, which would silently answer every question with "niets gevonden".
     */
    public function test_rubbish_narrows_nothing_rather_than_matching_nothing(): void
    {
        $this->assertSame([], $this->toolCall(['customer_ids' => ['', null, 0, 'kip']])->integerListArgument('customer_ids'));
        $this->assertSame([], $this->toolCall([])->integerListArgument('customer_ids'));
    }
}
