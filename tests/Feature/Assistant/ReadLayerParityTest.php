<?php

namespace Tests\Feature\Assistant;

use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\AssistantFilter;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Product;
use App\Models\Project;
use App\Models\Role;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The read layer took over rules the history search and the screens already
 * had. Nobody loses what they could read before it, and nobody gains past what
 * the screens show them: each case here is one way the first version got that
 * wrong.
 */
class ReadLayerParityTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function entry(string $description): Activity
    {
        return Activity::create([
            'description' => $description,
            'category' => 'update',
            'event_key' => 'test.updated',
            'required_permission' => null,
            'user_id' => null,
            'actor_type' => 'user',
            'actor_name' => 'Iemand',
            'occurred_at' => now(),
        ]);
    }

    /** @return array<int, string> */
    private function history(User $user, string $noun): array
    {
        $result = app(ToolExecutor::class)->run(new ToolCall('search_activity', ['subject_type' => $noun], $user));

        $this->assertFalse($result->is_error, json_encode($result->content));

        return array_column($result->content['activities'] ?? [], 'description');
    }

    private function records(User $user, array $arguments): mixed
    {
        $result = app(ToolExecutor::class)->run(new ToolCall('read_records', $arguments, $user));

        $this->assertFalse($result->is_error, json_encode($result->content));

        return $result->content;
    }

    private function forceDelete(object $record): void
    {
        method_exists($record, 'forceDelete') ? $record->forceDelete() : $record->delete();
    }

    /** "Wie heeft klant X verwijderd?": the record is gone, its history is not, for whoever may read every customer. */
    public function test_the_history_of_a_deleted_customer_or_project_stays_readable(): void
    {
        $customer = Customer::factory()->create();
        $customer->activities()->attach($this->entry('KLANT VERWIJDERD')->id);
        $project = Project::forceCreate(['title' => 'Weg', 'customer_id' => $customer->id, 'project_manager_id' => User::factory()->create()->id]);
        $project->activities()->attach($this->entry('PROJECT VERWIJDERD')->id);

        $this->forceDelete($project);
        $this->forceDelete($customer);

        $this->assertContains('KLANT VERWIJDERD', $this->history($this->userWith('customer.read'), 'klant'));
        $this->assertContains('PROJECT VERWIJDERD', $this->history($this->userWith('project.read'), 'project'));
        $this->assertNotContains('KLANT VERWIJDERD', $this->history($this->userWith('serviceorder.read'), 'klant'));
    }

    /**
     * A record gone for good is past any scope: only whoever reads every record of
     * the kind has its history. Deleting a werkbon takes its links along, so the
     * entry here hangs off one that is no longer in the table, as an entry of a
     * kind that keeps them would.
     */
    public function test_the_history_of_a_record_gone_for_good_is_for_whoever_reads_every_record(): void
    {
        $technician = $this->userWith('serviceorder.read_own');
        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $order->syncExecutingUsers([$technician->id]);
        $order->activities()->attach($this->entry('MIJN BON')->id);

        DB::table('activityables')->insert([
            'activity_id' => $this->entry('BON VERWIJDERD')->id,
            'activityable_type' => ServiceOrder::class,
            'activityable_id' => ServiceOrder::max('id') + 1000,
        ]);

        $his = $this->history($technician, 'werkbon');
        $this->assertContains('MIJN BON', $his);
        $this->assertNotContains('BON VERWIJDERD', $his);

        $everything = $this->history($this->userWith('serviceorder.read'), 'werkbon');
        $this->assertContains('MIJN BON', $everything);
        $this->assertContains('BON VERWIJDERD', $everything);
    }

    /** Whoever hands out roles reads their history, as before; the roles screen itself stays the admins'. */
    public function test_whoever_assigns_roles_reads_their_history_but_not_the_roles_screen(): void
    {
        $assigner = $this->userWith('user.assign_roles');
        $role = Role::firstOrCreate(['name' => 'Monteurtje']);
        $role->activities()->attach($this->entry('ROL GEWIJZIGD')->id);

        $this->assertContains('ROL GEWIJZIGD', $this->history($assigner, 'rol'));
        $this->assertSame(1, $this->records($assigner, ['kind' => 'rol', 'where' => [['field' => 'id', 'op' => '=', 'value' => $role->id]]])['aantal']);

        $this->actingAs($assigner)->get('/roles')->assertRedirect();
        $this->actingAs($this->admin())->get('/roles')->assertOk();
    }

    /**
     * Somebody assigned to a werkbon or an afspraak opens it on the screens
     * without the right to read them in general, and reads it and its history
     * here as well.
     */
    public function test_an_assigned_user_without_view_any_reads_what_he_is_assigned_to(): void
    {
        $user = $this->userWith('customer.read');
        $customer = Customer::factory()->create();
        $order = ServiceOrder::factory()->create(['customer_id' => $customer->id]);
        $order->syncExecutingUsers([$user->id]);
        $order->activities()->attach($this->entry('MIJN BON')->id);
        $other = ServiceOrder::factory()->create(['customer_id' => $customer->id]);
        $other->activities()->attach($this->entry('ANDERMANS BON')->id);

        $event = Event::factory()->create();
        $event->syncExecutingUsers([$user->id]);
        $event->activities()->attach($this->entry('MIJN AFSPRAAK')->id);
        Event::factory()->create()->activities()->attach($this->entry('ANDERMANS AFSPRAAK')->id);

        $this->assertTrue($user->can('view', $order));
        $this->assertFalse($user->can('viewAny', ServiceOrder::class));

        $werkbonnen = $this->history($user, 'werkbon');
        $this->assertContains('MIJN BON', $werkbonnen);
        $this->assertNotContains('ANDERMANS BON', $werkbonnen);

        $afspraken = $this->history($user, 'afspraak');
        $this->assertContains('MIJN AFSPRAAK', $afspraken);
        $this->assertNotContains('ANDERMANS AFSPRAAK', $afspraken);

        $this->assertSame(1, $this->records($user, ['kind' => 'werkbon', 'where' => [['field' => 'customer_id', 'op' => '=', 'value' => $customer->id]]])['aantal']);
        $this->assertSame(1, $this->records($user, ['kind' => 'afspraak', 'where' => [['field' => 'id', 'op' => 'in', 'value' => Event::query()->pluck('id')->all()]]])['aantal']);
    }

    /** Whoever sees every storing on the overview counts every storing too, and the link shows the same number. */
    public function test_a_see_all_holder_counts_what_the_storingen_overview_lists(): void
    {
        $user = $this->userWith('ticket.see_all');
        $asset = Asset::factory()->create(['customer_id' => Customer::factory()->create()->id, 'product_id' => Product::factory()->create()->id]);
        Ticket::factory()->count(3)->create(['asset_id' => $asset->id]);

        $content = $this->records($user, ['kind' => 'storing', 'where' => [], 'link' => true]);
        $this->assertArrayHasKey('link', $content, json_encode($content));

        $total = $this->actingAs($user)->get($content['link'])->assertOk()->viewData('page')['props']['tickets']['total'];

        $this->assertSame(Ticket::count(), $content['aantal']);
        $this->assertSame($content['aantal'], $total);
    }

    /** A filter whose field has since gone says it is out of date; one on a field closed to the opener says that. */
    public function test_the_bar_tells_an_outdated_filter_from_one_closed_to_the_opener(): void
    {
        $admin = $this->admin();

        $stale = AssistantFilter::record($admin, Customer::class, [['field' => 'kleur', 'op' => '=', 'value' => 'rood']], 'Rode klanten');
        $props = $this->actingAs($admin)->get($stale->url())->assertOk()->viewData('page')['props'];
        $this->assertSame([], $props['customers']['data']);
        $this->assertStringContainsString('klopt niet meer', $props['assistantFilter']['refused']);

        $gated = AssistantFilter::record($admin, Customer::class, [['field' => 'iban', 'op' => 'filled']], 'Klanten met een iban');
        $props = $this->actingAs($this->userWith('customer.read'))->get($gated->url())->assertOk()->viewData('page')['props'];
        $this->assertStringContainsString('niet mag zien', $props['assistantFilter']['refused']);
    }

    /** assistant:prune --months prunes the filter links on the same period as the conversations. */
    public function test_prune_takes_the_filters_with_the_same_months(): void
    {
        $admin = $this->admin();
        $old = AssistantFilter::record($admin, Customer::class, [], 'Klanten');
        $old->forceFill(['created_at' => now()->subMonths(2)->subDay()])->save();
        $recent = AssistantFilter::record($admin, Customer::class, [], 'Klanten');

        $this->artisan('assistant:prune', ['--months' => 2])->assertSuccessful();

        $this->assertNull(AssistantFilter::find($old->id));
        $this->assertNotNull(AssistantFilter::find($recent->id));

        $this->artisan('assistant:prune', ['--months' => 'zes'])->assertFailed();
    }

    /** An empty or mistyped ASSISTANT_KEEP_MONTHS keeps six months, not one. */
    public function test_an_unusable_keep_months_falls_back_to_six(): void
    {
        foreach (['', null, 'zes', '0', '-2'] as $value) {
            config(['assistant.keep_months' => $value]);
            $this->assertSame(6, AssistantFilter::keepMonths(), var_export($value, true));
        }

        config(['assistant.keep_months' => '3']);
        $this->assertSame(3, AssistantFilter::keepMonths());
    }
}
