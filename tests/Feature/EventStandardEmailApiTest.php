<?php

namespace Tests\Feature;

use App\Jobs\SendStandardEmailJob;
use App\Mail\StandardEmailMail;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Role;
use App\Models\StandardEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EventStandardEmailApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin_user(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function template_with_trigger(string $trigger_type): StandardEmail
    {
        $email = StandardEmail::create([
            'name' => 'Bevestiging',
            'subject' => 'Afspraak {{event_name}}',
            'body' => '<p>Op {{event_start_date}}</p>',
        ]);
        $email->triggers()->create(['trigger' => 'event_created', 'trigger_type' => $trigger_type]);

        return $email;
    }

    private function create_event_payload(Customer $customer, User $admin): array
    {
        return [
            'event_type_id' => EventType::factory()->create()->id,
            'status' => 'Gepland',
            'start' => now()->format('Y-m-d H:i'),
            'end' => now()->addHour()->format('Y-m-d H:i'),
            'no_service_order' => true,
            'customer_id' => $customer->id,
            'executing_user_ids' => [$admin->id],
        ];
    }

    public function test_store_returns_pending_standard_emails_for_confirm_trigger(): void
    {
        Bus::fake();
        $admin = $this->admin_user();
        $customer = Customer::factory()->create(['email' => 'klant@example.com']);
        $this->template_with_trigger('confirm');

        $response = $this->actingAs($admin)
            ->postJson('/api/events', $this->create_event_payload($customer, $admin))
            ->assertCreated();

        $pending = $response->json('pending_standard_emails');
        $this->assertCount(1, $pending);
        $this->assertEquals('confirm', $pending[0]['trigger_type']);
        $this->assertEquals('event_created', $pending[0]['trigger']);
        $this->assertEquals([], $response->json('queued_standard_emails'));
    }

    public function test_store_queues_background_trigger_and_reports_it(): void
    {
        Bus::fake();
        $admin = $this->admin_user();
        $customer = Customer::factory()->create(['email' => 'klant@example.com']);
        $this->template_with_trigger('background');

        $response = $this->actingAs($admin)
            ->postJson('/api/events', $this->create_event_payload($customer, $admin))
            ->assertCreated();

        $this->assertEquals(['Bevestiging'], $response->json('queued_standard_emails'));
        $this->assertEquals([], $response->json('pending_standard_emails'));
        Bus::assertDispatched(SendStandardEmailJob::class);
    }

    public function test_send_delivers_mail_and_logs_activity(): void
    {
        Mail::fake();
        $admin = $this->admin_user();
        $customer = Customer::factory()->create(['email' => 'klant@example.com']);
        $event = Event::factory()->create();
        $event->customers()->attach($customer->id);
        $email = StandardEmail::create(['name' => 'Handmatig', 'subject' => 'S', 'body' => 'B']);

        $this->actingAs($admin)
            ->postJson("/api/events/{$event->id}/standard-emails/send", [
                'standard_email_id' => $email->id,
                'to' => 'klant@example.com',
                'subject' => 'Onderwerp',
                'body' => '<p>Bericht</p>',
            ])
            ->assertOk();

        Mail::assertSent(StandardEmailMail::class);
        $this->assertEquals(
            1,
            $event->activities()->where('category', 'email')->count()
        );
    }

    public function test_send_is_forbidden_without_event_update_rights(): void
    {
        $stranger = User::factory()->create();
        $event = Event::factory()->create();
        $email = StandardEmail::create(['name' => 'X', 'subject' => 'S', 'body' => 'B']);

        $this->actingAs($stranger)
            ->postJson("/api/events/{$event->id}/standard-emails/send", [
                'standard_email_id' => $email->id,
                'to' => 'klant@example.com',
                'subject' => 'Onderwerp',
                'body' => '<p>Bericht</p>',
            ])
            ->assertForbidden();
    }
}
