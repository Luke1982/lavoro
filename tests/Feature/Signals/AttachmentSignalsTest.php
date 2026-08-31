<?php

namespace Tests\Feature\Signals;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Remark;
use App\Models\ServiceOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Documents, images and remarks announce their own removal. These go through the
 * real routes, so a controller that stops announcing fails here.
 */
class AttachmentSignalsTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
        ]);
    }

    public function test_deleting_a_document_announces_it_on_the_record_it_hung_from(): void
    {
        Storage::fake('public');

        $order = $this->order();
        $document = Document::create([
            'name' => 'Handleiding.pdf',
            'path' => 'documents/handleiding.pdf',
        ]);
        $order->documents()->attach($document->id, ['internal' => false]);

        $this->actingAs($this->admin())
            ->delete(route('documents.destroy', $document));

        $activity = Activity::where('event_key', 'document.removed')->sole();

        $this->assertSame(ServiceOrder::class, $activity->subject_type);
        $this->assertSame($order->id, $activity->subject_id);
        $this->assertSame('Document verwijderd: Handleiding.pdf', $activity->description);
        $this->assertSame('document', $activity->category);
    }

    public function test_deleting_a_remark_announces_it_on_the_record_it_hung_from(): void
    {
        $order = $this->order();
        $remark = Remark::create([
            'content' => 'Klant belt terug',
            'user_id' => $this->admin()->id,
        ]);
        $order->remarks()->attach($remark->id);

        $this->actingAs($this->admin())
            ->delete(route('remarks.destroy', $remark));

        $activity = Activity::where('event_key', 'remark.removed')->sole();

        $this->assertSame(ServiceOrder::class, $activity->subject_type);
        $this->assertSame($order->id, $activity->subject_id);
        $this->assertStringContainsString('Klant belt terug', $activity->description);
    }

    public function test_uploading_images_announces_one_entry_naming_how_many(): void
    {
        Storage::fake('public');

        $order = $this->order();

        $this->actingAs($this->admin())
            ->post(route('images.store'), [
                'images' => [
                    UploadedFile::fake()->image('een.jpg'),
                    UploadedFile::fake()->image('twee.jpg'),
                ],
                'imageable_type' => ServiceOrder::class,
                'imageable_id' => $order->id,
            ]);

        $activity = Activity::where('event_key', 'image.attached')->sole();

        $this->assertSame('2 afbeeldingen toegevoegd', $activity->description);
        $this->assertSame('image', $activity->category);
        $this->assertSame($order->id, $activity->subject_id);
    }
}
