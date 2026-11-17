<?php

namespace Tests\Feature\ServiceJobs;

use App\Enums\ServiceJobOutcomes;
use App\Http\Controllers\ServiceJobController;
use App\Mail\ServiceJobPdfMail;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Image;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Remark;
use App\Models\ServiceCheck;
use App\Models\ServiceCheckGroup;
use App\Models\ServiceCheckValue;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The keuring PDF has to come out for every shape a keuring can take: bare and
 * untouched, filled in with every kind of keurpunt, completed, combined with
 * parts, or about a part hanging under another machine. The combined werkbon
 * mail swallows a failing keuring PDF and simply leaves it out, so a broken
 * template would not show up there as an error — only as a missing attachment.
 */
class ServiceJobPdfTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private array $view_data = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        View::composer('pdf.servicejob', function ($view) {
            $this->view_data = $view->getData();
        });
    }

    private function productType(): ProductType
    {
        $product_type = ProductType::factory()->create(['typical_certificate_days' => 365]);
        $group = ServiceCheckGroup::create(['name' => 'Veiligheid', 'order' => 1]);
        $group->productTypes()->attach($product_type->id);

        foreach (['radio', 'checkgroup', 'boolean', 'number', 'text'] as $order => $type) {
            $check = ServiceCheck::create([
                'name' => 'Keurpunt ' . $type,
                'type' => $type,
                'order' => $order,
                'service_check_group_id' => $type === 'text' ? null : $group->id,
            ]);
            $check->productTypes()->attach($product_type->id);

            if (in_array($type, ['radio', 'checkgroup'])) {
                ServiceCheckValue::create(['service_check_id' => $check->id, 'value' => 'Goed', 'order' => 1]);
                ServiceCheckValue::create(['service_check_id' => $check->id, 'value' => 'Nee', 'order' => 2]);
            }
        }

        return $product_type;
    }

    private function asset(array $attributes = [], ?ProductType $product_type = null): Asset
    {
        $product = Product::factory()->create([
            'product_type_id' => ($product_type ?? $this->productType())->id,
        ]);

        return Asset::factory()->create(array_merge([
            'product_id' => $product->id,
            'customer_id' => Customer::factory()->create()->id,
            'next_service_date' => '2027-01-01',
        ], $attributes));
    }

    private function job(Asset $asset, array $attributes = []): ServiceJob
    {
        $order = ServiceOrder::factory()->create(['customer_id' => $asset->resolvedCustomerId()]);

        return ServiceJob::factory()->create(array_merge([
            'asset_id' => $asset->id,
            'service_order_id' => $order->id,
            'outcome' => ServiceJobOutcomes::nog_geen_uitkomst->value,
            'days_temporary_approval' => 0,
            'description' => null,
            'completed_on' => null,
        ], $attributes));
    }

    private function fillIn(ServiceJob $job): void
    {
        $author = User::factory()->create();

        foreach ($job->checkInstances()->with('serviceCheck.values')->get() as $instance) {
            $check = $instance->serviceCheck;
            match ($check->type) {
                'boolean' => $instance->update(['switch_state' => true, 'description' => 'Werkt naar behoren']),
                'number' => $instance->update(['description' => '12,5']),
                'text' => $instance->update(['description' => "Twee regels\nmet <tekens> & meer"]),
                default => $instance->values()->attach($check->values->pluck('id')->all()),
            };

            $remark = Remark::create(['user_id' => $author->id, 'content' => 'Opmerking bij ' . $check->name]);
            $instance->remarks()->attach($remark->id, ['internal' => false]);
        }

        $path = 'images/keurpunt.png';
        Storage::disk('public')->put($path, base64_decode(self::PNG));
        $image = Image::create(['name' => 'keurpunt.png', 'path' => $path]);
        $job->checkInstances()->first()->images()->attach($image->id, ['main' => false]);
    }

    private function assertPdfStreams(ServiceJob $job): void
    {
        $response = $this->withoutExceptionHandling()
            ->actingAs($this->admin())
            ->get("/servicejobs/{$job->id}/export/pdf");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_a_bare_keuring_without_location_checks_or_outcome_still_renders(): void
    {
        $product_type = ProductType::factory()->create();
        $customer = Customer::factory()->create(['address' => 'Klantstraat 1', 'postal_code' => '1234AB', 'city' => 'Utrecht']);
        $job = $this->job($this->asset(['customer_id' => $customer->id], $product_type));

        $this->assertPdfStreams($job);

        $this->assertNull($this->view_data['location']);
        $this->assertSame('Klantstraat 1, 1234AB Utrecht', $this->view_data['address']);
        $this->assertNull($this->view_data['newExpiryDate']);
        $this->assertSame([], $this->view_data['groups']);
    }

    public function test_a_keuring_filled_in_with_every_kind_of_keurpunt_remarks_and_a_photo_renders(): void
    {
        $job = $this->job($this->asset(), ['description' => 'Algemene opmerking']);
        $this->fillIn($job);

        $this->assertPdfStreams($job);

        $items = collect($this->view_data['groups'])->flatMap(fn ($group) => $group['items']);
        $this->assertCount(5, $items);
        $this->assertSame(['Veiligheid', 'Overige keurpunten'], array_column($this->view_data['groups'], 'name'));
    }

    public function test_the_address_is_that_of_the_location_the_machine_is_at(): void
    {
        $customer = Customer::factory()->create(['address' => 'Klantstraat 1']);
        $location = Location::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Magazijn Noord',
            'address' => 'Havenweg 7',
            'postal_code' => '9999ZZ',
            'city' => 'Groningen',
        ]);
        $job = $this->job($this->asset(['customer_id' => $customer->id, 'location_id' => $location->id]));

        $this->assertPdfStreams($job);

        $this->assertTrue($location->is($this->view_data['location']));
        $this->assertSame('Havenweg 7, 9999ZZ Groningen', $this->view_data['address']);
    }

    public function test_a_completed_keuring_shows_the_new_expiry_date(): void
    {
        $job = $this->job($this->asset(['next_service_date' => '2027-06-30']), [
            'outcome' => ServiceJobOutcomes::goedkeur->value,
            'completed_on' => '2026-06-30',
            'completed_by' => User::factory()->create()->id,
        ]);
        $this->fillIn($job);

        $this->assertPdfStreams($job);

        $this->assertSame('30-06-2027', $this->view_data['newExpiryDate']);
    }

    public function test_a_temporarily_approved_keuring_renders_its_extra_lines(): void
    {
        $job = $this->job($this->asset(['next_service_date' => '2026-12-30']), [
            'outcome' => ServiceJobOutcomes::tijdelijk_goedkeur->value,
            'days_temporary_approval' => 30,
            'completed_on' => '2026-11-30',
        ]);

        $this->assertPdfStreams($job);

        $this->assertTrue($this->view_data['isTempApproved']);
        $this->assertNotNull($this->view_data['tmpUntil']);
        $this->assertSame('30-12-2026', $this->view_data['newExpiryDate']);
    }

    public function test_a_rejected_keuring_shows_no_new_expiry_date(): void
    {
        $job = $this->job($this->asset(), [
            'outcome' => ServiceJobOutcomes::afkeur->value,
            'completed_on' => '2026-11-30',
        ]);

        $this->assertPdfStreams($job);

        $this->assertNull($this->view_data['newExpiryDate']);
    }

    public function test_a_combined_keuring_renders_from_the_parent_and_from_the_part(): void
    {
        $customer = Customer::factory()->create();
        $location = Location::factory()->create(['customer_id' => $customer->id, 'address' => 'Havenweg 7']);
        $machine = $this->asset(['customer_id' => $customer->id, 'location_id' => $location->id]);
        $part = $this->asset(['customer_id' => null, 'parent_asset_id' => $machine->id]);

        $parent_job = $this->job($machine);
        $child_job = $this->job($part, [
            'service_order_id' => $parent_job->service_order_id,
            'parent_service_job_id' => $parent_job->id,
        ]);
        $this->fillIn($parent_job);
        $this->fillIn($child_job);

        $this->assertPdfStreams($parent_job);
        $this->assertCount(1, $this->view_data['childJobSections']);

        $this->assertPdfStreams($child_job);
        $this->assertTrue($this->view_data['isChildJob']);
        $this->assertNotNull($this->view_data['parentJobLabel']);
    }

    public function test_a_part_under_a_machine_takes_customer_and_location_from_that_machine(): void
    {
        $customer = Customer::factory()->create();
        $location = Location::factory()->create(['customer_id' => $customer->id, 'address' => 'Havenweg 7']);
        $machine = $this->asset(['customer_id' => $customer->id, 'location_id' => $location->id]);
        $part = $this->asset(['customer_id' => null, 'parent_asset_id' => $machine->id]);

        $this->assertPdfStreams($this->job($part));

        $this->assertTrue($customer->is($this->view_data['customer']));
        $this->assertTrue($location->is($this->view_data['location']));
    }

    public function test_the_keuring_pdf_can_be_emailed(): void
    {
        Mail::fake();
        $customer = Customer::factory()->create(['email' => 'klant@example.test']);
        $job = $this->job($this->asset(['customer_id' => $customer->id]));
        $this->fillIn($job);

        $this->withoutExceptionHandling()
            ->actingAs($this->admin())
            ->post("/servicejobs/{$job->id}/email-pdf")
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(ServiceJobPdfMail::class);
        $this->assertTrue($job->refresh()->sent_to_customer);
    }

    public function test_the_pdf_attached_to_the_combined_werkbon_mail_is_generated(): void
    {
        $job = $this->job($this->asset());
        $this->fillIn($job);

        $output = app(ServiceJobController::class)->exportPdfForCombine($job);

        $this->assertStringStartsWith('%PDF', $output);
    }
}
