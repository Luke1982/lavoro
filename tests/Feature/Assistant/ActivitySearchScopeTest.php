<?php

namespace Tests\Feature\Assistant;

use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * History has no permission of its own: you may read an entry exactly when you
 * may read its subject, and never when the entry itself is gated.
 *
 * Two independent narrowings have to hold at once, and they answer different
 * questions. Subject scoping asks whether this person may see the record at all.
 * Activity::visibleTo asks whether the values in this particular entry are
 * sensitive. Dropping either one leaks, so both are covered here.
 */
class ActivitySearchScopeTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
        ]);
    }

    private function entry(string $description, ?ServiceOrder $order, ?string $permission = null): Activity
    {
        return Activity::create([
            'description' => $description,
            'category' => 'update',
            'event_key' => 'serviceorder.updated',
            'required_permission' => $permission,
            'subject_type' => $order ? ServiceOrder::class : null,
            'subject_id' => $order?->id,
            'user_id' => null,
            'actor_type' => 'user',
            'actor_name' => 'Iemand',
            'occurred_at' => now(),
        ]);
    }

    /** @return array<int, string> */
    private function search(User $user, array $arguments = []): array
    {
        $result = app(ToolExecutor::class)->run(
            new ToolCall('search_activity', $arguments + ['limit' => 50], $user)
        );

        if ($result->is_error) {
            return [];
        }

        return array_column($result->content['activities'] ?? [], 'description');
    }

    public function test_a_technician_sees_the_history_of_a_werkbon_they_execute(): void
    {
        $user = $this->userWith('serviceorder.read_own');
        $mine = $this->order();
        $mine->syncExecutingUsers([$user->id]);

        $this->entry('MIJN WERKBON', $mine);

        $this->assertContains('MIJN WERKBON', $this->search($user));
    }

    public function test_a_technician_never_sees_the_history_of_a_werkbon_they_are_not_on(): void
    {
        $user = $this->userWith('serviceorder.read_own');
        $mine = $this->order();
        $mine->syncExecutingUsers([$user->id]);

        $this->entry('MIJN WERKBON', $mine);
        $this->entry('ANDERMANS WERKBON', $this->order());

        $found = $this->search($user);

        $this->assertContains('MIJN WERKBON', $found);
        $this->assertNotContains('ANDERMANS WERKBON', $found, 'history of an invisible werkbon leaked');
    }

    /**
     * Entries written before the subject columns existed cannot be attributed to
     * a record, so their visibility cannot be established. They stay out rather
     * than defaulting to readable.
     */
    public function test_an_entry_without_a_subject_is_never_returned(): void
    {
        $this->entry('WEESKIND', null);

        $this->assertNotContains('WEESKIND', $this->search($this->admin()));
    }

    public function test_a_gated_entry_is_withheld_from_someone_without_the_permission(): void
    {
        $user = $this->userWith('serviceorder.read_own');
        $order = $this->order();
        $order->syncExecutingUsers([$user->id]);

        $this->entry('GEWONE WIJZIGING', $order);
        $this->entry('MARGE 45 PROCENT', $order, 'serviceorder.see_financials');

        $found = $this->search($user);

        $this->assertContains('GEWONE WIJZIGING', $found);
        $this->assertNotContains('MARGE 45 PROCENT', $found, 'a gated financial entry leaked');
    }

    public function test_a_gated_entry_is_returned_to_someone_holding_the_permission(): void
    {
        $user = $this->userWithPermissions('serviceorder.read_own', 'serviceorder.see_financials');
        $order = $this->order();
        $order->syncExecutingUsers([$user->id]);

        $this->entry('MARGE 45 PROCENT', $order, 'serviceorder.see_financials');

        $this->assertContains('MARGE 45 PROCENT', $this->search($user));
    }

    /**
     * Both narrowings apply together: holding the sensitive permission does not
     * grant sight of a record you were never allowed to open.
     */
    public function test_the_financial_permission_does_not_widen_which_records_are_visible(): void
    {
        $user = $this->userWithPermissions('serviceorder.read_own', 'serviceorder.see_financials');
        $user->refresh();

        $this->entry('ANDERMANS MARGE', $this->order(), 'serviceorder.see_financials');

        $this->assertNotContains('ANDERMANS MARGE', $this->search($user));
    }

    public function test_an_id_without_a_type_is_refused_rather_than_mixing_record_kinds(): void
    {
        $result = app(ToolExecutor::class)->run(
            new ToolCall('search_activity', ['subject_id' => 42], $this->admin())
        );

        $this->assertTrue($result->is_error);
        $this->assertStringContainsString('subject_type', $result->toModelContent());
    }
}
