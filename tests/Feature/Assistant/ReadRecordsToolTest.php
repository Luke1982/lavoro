<?php

namespace Tests\Feature\Assistant;

use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolResult;
use App\Enums\ProjectStatuses;
use App\Jobs\PruneAssistantFiltersJob;
use App\Models\AssistantFilter;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Project;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * read_records answers "hoeveel" and "welke" in the database, and hands out a
 * link to the overview with exactly those records instead of a list in the chat.
 */
class ReadRecordsToolTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function read(User $user, array $arguments): ToolResult
    {
        return app(ToolExecutor::class)->run(new ToolCall('read_records', $arguments, $user));
    }

    private function order(Customer $customer, string $order_date): ServiceOrder
    {
        return ServiceOrder::factory()->create(['customer_id' => $customer->id, 'order_date' => $order_date]);
    }

    public function test_it_counts_with_a_date_a_relation_and_a_missing_relation(): void
    {
        $tiel = Customer::factory()->create(['city' => 'Tiel']);
        $elders = Customer::factory()->create(['city' => 'Elders']);

        $old_in_tiel = $this->order($tiel, '2026-09-01');
        $this->order($tiel, '2026-11-20');
        $this->order($elders, '2026-09-01');
        $planned = $this->order($tiel, '2026-09-02');
        $planned->events()->attach(Event::factory()->create()->id);

        $where = [
            ['field' => 'order_date', 'op' => '<', 'value' => '2026-10-25'],
            ['has' => 'events', 'not' => true],
            ['field' => 'customer.city', 'op' => '=', 'value' => 'Tiel'],
        ];
        $result = $this->read($this->admin(), ['kind' => 'werkbon', 'where' => $where]);

        $this->assertFalse($result->is_error, $result->toModelContent());
        $this->assertSame(1, $result->content['aantal']);
        $this->assertArrayNotHasKey('link', $result->content);

        $that_one = $this->read($this->admin(), ['kind' => 'werkbon', 'where' => [...$where, ['field' => 'id', 'op' => '=', 'value' => $old_in_tiel->id]]]);
        $this->assertSame(1, $that_one->content['aantal'], 'the one that matched is not the old werkbon in Tiel without an appointment');
    }

    public function test_a_grouping_names_the_records_its_keys_point_at(): void
    {
        $customer = Customer::factory()->create(['name' => 'Groepeer BV']);
        $this->order($customer, '2026-09-01');
        $this->order($customer, '2026-09-02');

        $result = $this->read($this->admin(), [
            'kind' => 'werkbon',
            'where' => [['field' => 'customer_id', 'op' => '=', 'value' => $customer->id]],
            'group' => 'customer_id',
        ]);

        $this->assertFalse($result->is_error, $result->toModelContent());
        $this->assertSame([['customer_id' => $customer->id, 'naam' => 'Groepeer BV', 'aantal' => 2]], $result->content['groepen']);
    }

    public function test_a_wrong_field_or_operator_is_refused_with_what_there_is(): void
    {
        $unknown = $this->read($this->admin(), ['kind' => 'werkbon', 'where' => [['field' => 'kleur', 'op' => '=', 'value' => 'rood']]]);
        $this->assertTrue($unknown->is_error);
        $this->assertStringContainsString('order_date', $unknown->toModelContent());

        $operator = $this->read($this->admin(), ['kind' => 'werkbon', 'where' => [['field' => 'order_date', 'op' => 'tussen', 'value' => '2026-01-01']]]);
        $this->assertTrue($operator->is_error);

        $date = $this->read($this->admin(), ['kind' => 'werkbon', 'where' => [['field' => 'order_date', 'op' => '<', 'value' => 'vorige week']]]);
        $this->assertTrue($date->is_error);
        $this->assertStringContainsString('JJJJ-MM-DD', $date->toModelContent());

        $method = $this->read($this->admin(), ['kind' => 'werkbon', 'where' => [['has' => 'delete']]]);
        $this->assertTrue($method->is_error);
    }

    public function test_a_link_opens_the_overview_with_exactly_those_records_and_a_bar_above_it(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();
        $wanted = $this->order($customer, '2026-09-01');
        $other = $this->order($customer, '2026-11-20');

        $result = $this->read($admin, [
            'kind' => 'werkbon',
            'where' => [
                ['field' => 'customer_id', 'op' => '=', 'value' => $customer->id],
                ['field' => 'order_date', 'op' => '<', 'value' => '2026-10-25'],
            ],
            'link' => true,
        ]);

        $this->assertFalse($result->is_error, $result->toModelContent());
        $this->assertSame(1, $result->content['aantal']);
        $this->assertStringStartsWith('/serviceorders?filter=', $result->content['link']);

        $props = $this->actingAs($admin)->get($result->content['link'])->assertOk()->viewData('page')['props'];
        $ids = collect($props['serviceOrders']['data'])->pluck('id');

        $this->assertTrue($ids->contains($wanted->id));
        $this->assertFalse($ids->contains($other->id));
        $this->assertSame($result->content['samenvatting'], $props['assistantFilter']['summary']);
        $this->assertNull($props['assistantFilter']['refused']);
        $this->assertFalse($props['assistantFilter']['narrowed']);

        $searched = $this->actingAs($admin)->get($result->content['link'] . '&search=x')->viewData('page')['props'];
        $this->assertTrue($searched['assistantFilter']['narrowed']);

        $unfiltered = $this->actingAs($admin)->get('/serviceorders')->viewData('page')['props'];
        $this->assertNull($unfiltered['assistantFilter']);
    }

    /** The filter is compiled again for whoever opens it: a field they may not read empties the list. */
    public function test_a_shared_link_on_a_field_the_opener_may_not_read_shows_nothing(): void
    {
        $customer = Customer::factory()->create(['iban' => 'NL91ABNA0417164300']);
        $filter = AssistantFilter::record($this->admin(), Customer::class, [['field' => 'iban', 'op' => 'filled']], 'Klanten met een iban');

        $reader = $this->userWith('customer.read');
        $props = $this->actingAs($reader)->get($filter->url())->assertOk()->viewData('page')['props'];

        $this->assertFalse(collect($props['customers']['data'])->pluck('id')->contains($customer->id));
        $this->assertNotNull($props['assistantFilter']['refused']);
        $this->assertNull($props['assistantFilter']['summary'], 'the summary names the field the opener may not read');
    }

    public function test_old_filters_are_pruned(): void
    {
        $old = AssistantFilter::record($this->admin(), Customer::class, [], 'Klanten');
        $old->forceFill(['created_at' => now()->subMonths(AssistantFilter::keepMonths())->subDay()])->save();
        $new = AssistantFilter::record($this->admin(), Customer::class, [], 'Klanten');

        (new PruneAssistantFiltersJob)->handle();

        $this->assertNull(AssistantFilter::find($old->id));
        $this->assertNotNull(AssistantFilter::find($new->id));
    }

    /** A link to a filter that is gone, or that belongs to another list, shows nothing and says why. */
    public function test_a_filter_that_is_gone_or_for_another_list_shows_nothing(): void
    {
        $admin = $this->admin();
        Customer::factory()->create();

        $gone = $this->actingAs($admin)->get('/customers?filter=' . Str::uuid())->assertOk()->viewData('page')['props'];
        $this->assertSame([], $gone['customers']['data']);
        $this->assertStringContainsString('bestaat niet meer', $gone['assistantFilter']['refused']);
        $this->assertNull($gone['assistantFilter']['summary']);

        $werkbonnen = AssistantFilter::record($admin, ServiceOrder::class, [], 'Werkbonnen');
        $elsewhere = $this->actingAs($admin)->get('/customers?filter=' . $werkbonnen->uuid)->assertOk()->viewData('page')['props'];
        $this->assertSame([], $elsewhere['customers']['data']);
        $this->assertNotNull($elsewhere['assistantFilter']['refused']);

        $nowhere = $this->actingAs($admin)->get('/planner?filter=' . Str::uuid())->viewData('page')['props'];
        $this->assertNull($nowhere['assistantFilter'] ?? null);
    }

    /** A list that opens on a narrower default (the running projects) opens on every status with a filter. */
    public function test_a_filtered_list_does_not_narrow_it_with_its_own_default(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();
        $finished = Project::forceCreate(['title' => 'Klaar', 'customer_id' => $customer->id, 'project_manager_id' => $admin->id, 'status' => ProjectStatuses::afgerond->value]);

        $result = $this->read($admin, ['kind' => 'project', 'link' => true, 'where' => [['field' => 'id', 'op' => '=', 'value' => $finished->id]]]);
        $this->assertSame(1, $result->content['aantal']);

        $props = $this->actingAs($admin)->get($result->content['link'])->assertOk()->viewData('page')['props'];
        $this->assertSame([$finished->id], collect($props['projects']['data'])->pluck('id')->all());
        $this->assertSame('all', $props['list']['status']);
    }

    public function test_refusals_say_what_there_is(): void
    {
        $admin = $this->admin();

        $not_read = $this->read($admin, ['kind' => 'koppeling']);
        $this->assertTrue($not_read->is_error);
        $this->assertStringContainsString('leest geen', $not_read->toModelContent());

        $reader = $this->userWith('customer.read');
        $unknown = $this->read($reader, ['kind' => 'fiets']);
        $this->assertStringContainsString('klant', $unknown->toModelContent());
        $this->assertStringNotContainsString('werkbon', $unknown->toModelContent());

        $relation = $this->read($admin, ['kind' => 'werkbon', 'where' => [['has' => 'fietsen']]]);
        $this->assertStringContainsString('events', $relation->toModelContent());

        $both = $this->read($admin, ['kind' => 'werkbon', 'id' => 1, 'where' => [['field' => 'id', 'op' => '=', 'value' => 1]]]);
        $this->assertTrue($both->is_error);

        $unique = $this->read($admin, ['kind' => 'werkbon', 'group' => 'id']);
        $this->assertTrue($unique->is_error);
        $this->assertStringContainsString('uniek', $unique->toModelContent());

        $infinite = $this->read($admin, ['kind' => 'werkbon', 'where' => [['field' => 'customer_id', 'op' => '<', 'value' => '1e999']]]);
        $this->assertTrue($infinite->is_error);

        $not = $this->read($admin, ['kind' => 'werkbon', 'where' => [['has' => 'events', 'not' => 'misschien']]]);
        $this->assertTrue($not->is_error);
    }

    /** MySQL compares text with a number as numbers, so "Tiel" = 0 would match; text is compared as text. */
    public function test_a_number_given_for_a_text_field_is_compared_as_text(): void
    {
        $customer = Customer::factory()->create(['city' => 'Tiel']);

        $result = $this->read($this->admin(), ['kind' => 'klant', 'where' => [
            ['field' => 'id', 'op' => '=', 'value' => $customer->id],
            ['field' => 'city', 'op' => 'in', 'value' => [0]],
        ]]);

        $this->assertFalse($result->is_error, $result->toModelContent());
        $this->assertSame(0, $result->content['aantal']);
    }
}
