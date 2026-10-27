<?php

namespace Tests\Feature\Demo;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventUserExecution;
use App\Models\Image;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Project;
use App\Models\Role;
use App\Models\ServiceOrder;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Invoicer;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesLandlordData;
use Tests\TestCase;

/**
 * The demo is what a prospect sees first. Every promise it makes -- a face for
 * every role, a product on every type, a planning around today, open work, an
 * asset at every customer -- is checked here, because a demo that breaks in
 * front of someone does more harm than having none.
 *
 * One seeding, many questions: the seeder takes a while, and every question
 * below is about the same finished installation.
 */
class DemoSeederTest extends TestCase
{
    use MakesLandlordData;

    /** A Wednesday morning, so this week has work behind it and ahead of it. */
    private const NOW = '2026-09-09 10:15';

    public function test_the_demo_is_complete_and_credible(): void
    {
        Storage::fake('public');

        /** The test tenant gets no roles from its own seeder; a real tenant has these. */
        foreach (array_keys(include base_path('database/seeders/data/tenant_roles.php')) as $name) {
            Role::firstOrCreate(['name' => $name]);
        }

        $now = CarbonImmutable::parse(self::NOW, config('app.display_timezone'));

        /**
         * Travelled to in UTC: Carbon gives every date it reads without a zone
         * the zone of the moment travelled to, and the database stores UTC. In
         * Amsterdam time a stored 08:30 would read back as 08:30 local.
         */
        $this->travelTo($now->utc());

        (new DemoSeeder($now))->run();

        $this->everyRoleHasAPersonWithAFace();
        $this->theProductTreeHasAProductWithAPictureOnEveryLeaf();
        $this->everyCustomerHasMachines();
        $this->thePlanningCoversTwoWeeksBackAndTwoAhead($now);
        $this->assertSame(0, EventUserExecution::count(), 'registered times grey the planning out');
        $this->theProjectsRun();
        $this->thereIsWorkWaitingForADate();
        $this->theDeskHasTicketsInEveryState();
    }

    private function everyRoleHasAPersonWithAFace(): void
    {
        foreach (array_keys(include base_path('database/seeders/data/tenant_roles.php')) as $role) {
            $this->assertTrue(
                User::whereHas('roles', fn ($query) => $query->where('name', $role))->exists(),
                "nobody has the role {$role}, so the demo cannot show what it sees"
            );
        }

        foreach (User::where('email', 'like', '%@lavorofsm.nl')->get() as $user) {
            $this->assertSame(
                ["users/{$user->id}/avatar/" . strtok($user->email, '@') . '.jpg'],
                Storage::disk('public')->files("users/{$user->id}/avatar"),
                "{$user->name} should have a photo, and only that"
            );
        }

        $this->assertTrue(User::where('email', 'demo@lavorofsm.nl')->exists(), 'the demo login is missing');
        $this->assertGreaterThanOrEqual(5, User::where('plannable', true)->count(), 'a planning needs mechanics');
    }

    private function theProductTreeHasAProductWithAPictureOnEveryLeaf(): void
    {
        $wall_unit = ProductType::where('name', 'Binnendeel wandmodel')->firstOrFail();

        $this->assertSame('Single split', $wall_unit->parent->name);
        $this->assertSame('Airconditioning', $wall_unit->parent->parent->name);

        foreach (ProductType::doesntHave('children')->get() as $leaf) {
            $this->assertTrue($leaf->products()->exists(), "no product of type {$leaf->name}");
        }

        foreach (Product::with('images')->get() as $product) {
            $image = $product->images->first();

            $this->assertInstanceOf(Image::class, $image, "{$product->display_name} has no picture");
            $this->assertTrue(Storage::disk('public')->exists($image->path), "the picture of {$product->display_name} is not on disk");
        }
    }

    private function everyCustomerHasMachines(): void
    {
        $without = Customer::where('email', 'like', '%.demo')->doesntHave('assets')->pluck('name');

        $this->assertEmpty($without, 'customers without a single machine: ' . $without->implode(', '));
        $this->assertTrue(Asset::whereNull('next_service_date')->doesntExist(), 'every machine has a next service date');
    }

    private function thePlanningCoversTwoWeeksBackAndTwoAhead(CarbonImmutable $now): void
    {
        $zone = config('app.display_timezone');
        $monday = $now->startOfWeek();
        $events = Event::with('executingUsers', 'serviceOrders')->get();

        foreach ([-2, -1, 0, 1, 2] as $week) {
            $from = $monday->addWeeks($week);

            $this->assertTrue(
                $events->contains(fn (Event $event) => $event->start->setTimezone($zone)->betweenIncluded($from, $from->addDays(5))),
                "nothing planned in the week of {$from->format('d-m')}"
            );
        }

        foreach ($events as $event) {
            $this->assertNotEmpty($event->executingUsers, "appointment {$event->id} has no mechanic");
            $this->assertNotEmpty($event->serviceOrders, "appointment {$event->id} belongs to no order");

            $expected = $event->end->lessThanOrEqualTo($now) ? 'Afgerond' : ($event->start->lessThanOrEqualTo($now) ? 'Gaande' : 'Gepland');
            $this->assertSame($expected, $event->status, "appointment {$event->id} on {$event->start} has the wrong status");
        }
    }

    private function theProjectsRun(): void
    {
        $projects = Project::with('milestones', 'projectManager.roles', 'serviceOrders.taskInstances', 'serviceOrders.events.executingUsers')->get();

        $this->assertEqualsCanonicalizing(['Afgerond', 'Gestart', 'Niet gestart'], $projects->pluck('status')->unique()->values()->all());

        foreach ($projects as $project) {
            $this->assertNotEmpty($project->milestones, "{$project->title} has no milestones");
            $this->assertContains('Projectleider', $project->projectManager->roles->pluck('name'), "{$project->title} is not led by the project leader");
            $this->assertSame('Totaal', last($project->financial_notes['data'])[0], "{$project->title} has no budget");

            foreach ($project->serviceOrders as $order) {
                $this->assertNotEmpty($order->taskInstances, "phase {$order->description} has no tasks");
            }
        }

        $finished = $projects->firstWhere('status', 'Afgerond');

        $this->assertTrue($finished->milestones->every(fn ($milestone) => $milestone->actual_date !== null), 'a finished project with open milestones');
        $this->assertTrue($finished->serviceOrders->every(fn (ServiceOrder $order) => $order->is_closed), 'a finished project with open work');

        /** A project day is the whole day: nothing else for its team. */
        foreach ($projects->flatMap->serviceOrders->flatMap->events as $event) {
            foreach ($event->executingUsers as $mechanic) {
                $that_day = Event::whereHas('executingUsers', fn ($query) => $query->whereKey($mechanic->id))
                    ->whereDate('start', $event->start->toDateString())->count();

                $this->assertSame(1, $that_day, "{$mechanic->name} has more than the project on {$event->start->toDateString()}");
            }
        }
    }

    private function thereIsWorkWaitingForADate(): void
    {
        $waiting = ServiceOrder::whereHas('serviceOrderStage', fn ($query) => $query->where('is_plannable_state', true))
            ->doesntHave('events')->count();

        $this->assertGreaterThanOrEqual(5, $waiting, 'the planner has nothing to plan');
    }

    private function theDeskHasTicketsInEveryState(): void
    {
        foreach (['Open', 'In behandeling', 'Gesloten'] as $status) {
            $this->assertTrue(Ticket::where('status', $status)->exists(), "no ticket is {$status}");
        }
    }

    /**
     * The demo is rebuilt every night with a fresh start date. Invoicing it
     * would spend a real invoice number, every single night.
     */
    public function test_the_demo_is_never_invoiced(): void
    {
        $demo = $this->tenantRow(['subscription_started_on' => '2026-09-01', 'package_key' => 'business']);
        $demo->demo = true;
        $demo->save();

        $invoicer = new Invoicer(Tenant::on('central')->find($demo->id));

        $this->assertFalse($invoicer->isDue(CarbonImmutable::parse('2026-09-10')));
        $this->assertSame([], $invoicer->preview(CarbonImmutable::parse('2026-09-10'))['lines']);
    }
}
