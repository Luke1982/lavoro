<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\Contracts\ModelReply;
use App\Domain\Assistant\Contracts\StopReason;
use App\Domain\Assistant\Contracts\TalksToModel;
use App\Domain\Assistant\Contracts\TokenUsage;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolResult;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Image;
use App\Models\Product;
use App\Models\ServiceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The photos already hanging on a record.
 *
 * A monteur photographs the machines as he goes and they sit on the werkbon
 * proving he was there. They are the same typeplaatjes the catalogue is
 * missing; asking somebody to download and re-upload them one at a time is an
 * errand nobody runs twice.
 */
class ViewImagesTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private function seeing(bool $sees = true): void
    {
        $this->app->bind(TalksToModel::class, fn () => new EyesModel($sees));
    }

    private function photoOn(ServiceOrder $order, string $name, bool $internal = false): Image
    {
        $path = 'uploaded/serviceorder/' . $order->id . '/' . $name;
        Storage::disk('public')->put($path, 'nep-bytes');

        $image = Image::create(['name' => $name, 'path' => $path]);
        $order->images()->attach($image->id, ['internal' => $internal]);

        return $image;
    }

    private function look(array $arguments): ToolResult
    {
        return app(ToolExecutor::class)->run(new ToolCall(
            'view_images',
            $arguments,
            $this->userWith('serviceorder.read'),
        ));
    }

    public function test_the_photos_on_a_werkbon_are_handed_to_the_model(): void
    {
        Storage::fake('public');
        $this->seeing();

        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $this->photoOn($order, 'plaatje.jpg');

        $result = $this->look(['subject_type' => 'werkbon', 'subject_id' => $order->id]);

        $this->assertFalse($result->is_error, (string) $result->summary);
        $this->assertCount(1, $result->attachments, 'the photo never became something the model can see');
        $this->assertSame('image/jpeg', $result->attachments[0]->media_type);
        $this->assertSame(base64_encode('nep-bytes'), $result->attachments[0]->base64);
    }

    /**
     * A blind model is told so rather than handed pictures it cannot see.
     *
     * Whether a conversation can see is settled before the first round — an
     * assistant turn carries the supplier's own raw content, so it cannot change
     * providers halfway — which means the tool can genuinely land on a model
     * without eyes, and must say so instead of trying.
     */
    public function test_a_blind_model_is_told_so_instead_of_being_handed_photos(): void
    {
        Storage::fake('public');
        $this->seeing(false);

        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $this->photoOn($order, 'plaatje.jpg');

        $result = $this->look(['subject_type' => 'werkbon', 'subject_id' => $order->id]);

        $this->assertTrue($result->is_error);
        $this->assertSame([], $result->attachments);
        $this->assertStringContainsString('zonder beeld', (string) $result->summary);
    }

    public function test_more_photos_than_fit_are_reported_with_how_to_get_the_rest(): void
    {
        Storage::fake('public');
        $this->seeing();

        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        foreach (range(1, 6) as $number) {
            $this->photoOn($order, 'plaatje-' . $number . '.jpg');
        }

        $result = $this->look(['subject_type' => 'werkbon', 'subject_id' => $order->id]);

        $this->assertCount(4, $result->attachments);
        $this->assertSame(6, $result->content['total']);
        $this->assertStringContainsString('skip=4', $result->content['note']);

        $rest = $this->look(['subject_type' => 'werkbon', 'subject_id' => $order->id, 'skip' => 4]);

        $this->assertCount(2, $rest->attachments);
    }

    public function test_a_record_without_photos_says_so_plainly(): void
    {
        Storage::fake('public');
        $this->seeing();

        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        $result = $this->look(['subject_type' => 'werkbon', 'subject_id' => $order->id]);

        $this->assertFalse($result->is_error);
        $this->assertSame([], $result->attachments);
        $this->assertStringContainsString('Geen', (string) $result->summary);
    }

    /** A photo is exactly as visible as the record it hangs on. */
    public function test_a_werkbon_somebody_may_not_see_yields_no_photos(): void
    {
        Storage::fake('public');
        $this->seeing();

        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $this->photoOn($order, 'plaatje.jpg');

        $result = app(ToolExecutor::class)->run(new ToolCall(
            'view_images',
            ['subject_type' => 'werkbon', 'subject_id' => $order->id],
            $this->userWith('serviceorder.read_own'),
        ));

        $this->assertTrue($result->is_error);
        $this->assertSame([], $result->attachments);
    }

    public function test_placeholder_machines_are_found_for_backfilling(): void
    {
        $user = $this->userWith('asset.read');
        $customer = Customer::factory()->create();

        $vague = Product::factory()->create(['model' => 'Onbekend model']);
        $known = Product::factory()->create(['model' => 'SRK 25 ZS-W']);

        $placeholder = Asset::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => $vague->id,
            'serial_number' => 'onbekend',
        ]);
        Asset::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => $known->id,
            'serial_number' => '1101007320',
        ]);

        $found = app(ToolExecutor::class)->run(new ToolCall('find_placeholder_records', [], $user));

        $this->assertCount(1, $found->content['assets']);
        $this->assertSame($placeholder->id, $found->content['assets'][0]['asset_id']);
        $this->assertTrue($found->content['assets'][0]['serial_is_placeholder']);
        $this->assertTrue($found->content['assets'][0]['product_is_placeholder']);
    }

    /**
     * The monteur's own photos are the ones with the typeplaatje in them.
     *
     * images() hides anything marked internal, because that relation decides
     * what a customer sees on a PDF. Reading only that half read the wrong half:
     * the working photos, the whole reason for asking, were the ones left out.
     */
    public function test_the_internal_photos_are_read_as_well(): void
    {
        Storage::fake('public');
        $this->seeing();

        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $this->photoOn($order, 'voor-de-klant.jpg');
        $this->photoOn($order, 'typeplaatje.jpg', internal: true);

        $result = $this->look(['subject_type' => 'werkbon', 'subject_id' => $order->id]);

        $this->assertCount(2, $result->attachments, "the monteur's own photos were left out");
        $this->assertSame(2, $result->content['total']);

        $internal = collect($result->content['images'])->firstWhere('name', 'typeplaatje.jpg');

        $this->assertNotNull($internal, 'the internal photo never came back');
        $this->assertTrue($internal['internal'], 'nothing said which photos were the internal ones');
    }
}

/** A model with or without eyes, so the refusal can be tested either way. */
class EyesModel implements TalksToModel
{
    public function __construct(private readonly bool $sees) {}

    public function seesImages(): bool
    {
        return $this->sees;
    }

    public function readsDocuments(): bool
    {
        return true;
    }

    public function send(array $turns, array $tools, string $system): ModelReply
    {
        return new ModelReply(
            texts: ['Klaar.'],
            tool_calls: [],
            usage: new TokenUsage(1, 1, 0, 0),
            stop_reason: StopReason::finished,
            model: 'eyes-fake',
            raw: null,
        );
    }
}
