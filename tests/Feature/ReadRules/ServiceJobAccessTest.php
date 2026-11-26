<?php

namespace Tests\Feature\ReadRules;

use App\Enums\ServiceJobOutcomes;
use App\Mail\ServiceJobPdfMail;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Image;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\ServiceCheck;
use App\Models\ServiceCheckInstance;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\TrayItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AttachesPhotos;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A keuring is opened, exported, mailed, changed and removed by id, so every one of
 * those doors asks ServiceJobPolicy: the right for the action, on a keuring whose
 * werkbon this person may open (ServiceJob::visibleTo).
 */
class ServiceJobAccessTest extends TestCase
{
    use AttachesPhotos;
    use CreatesAuthenticatedUsers;

    private const READER_WITH_PHOTO_RIGHTS = [
        'serviceorder.read',
        'servicejob.read',
        'image.upload',
        'image.delete',
    ];

    private const MONTEUR = [
        'serviceorder.read_own',
        'servicejob.read',
        'servicejob.update',
        'servicejob.delete',
        'servicejob.export_pdf',
        'servicejob.mail_pdf',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    private function job(?User $executing = null): ServiceJob
    {
        $product_type = ProductType::factory()->create();
        $check = ServiceCheck::create(['name' => 'Lekdicht', 'type' => 'boolean', 'order' => 1]);
        $check->productTypes()->attach($product_type->id);

        $customer = Customer::factory()->create(['email' => 'klant@example.test']);
        $asset = Asset::factory()->create([
            'product_id' => Product::factory()->create(['product_type_id' => $product_type->id])->id,
            'customer_id' => $customer->id,
        ]);
        $order = ServiceOrder::factory()->create(['customer_id' => $customer->id]);

        if ($executing) {
            $order->syncExecutingUsers([$executing->id]);
        }

        return ServiceJob::factory()->create([
            'asset_id' => $asset->id,
            'service_order_id' => $order->id,
            'outcome' => ServiceJobOutcomes::goedkeur->value,
            'days_temporary_approval' => 0,
            'completed_on' => '2026-11-01',
        ]);
    }

    public function test_a_keuring_on_another_werkbon_cannot_be_opened_exported_mailed_changed_or_removed(): void
    {
        $monteur = $this->userWithPermissions(...self::MONTEUR);
        $job = $this->job();
        $check = $job->checkInstances()->firstOrFail();

        $this->actingAs($monteur);
        $this->getJson("/servicejobs/{$job->id}")->assertForbidden();
        $this->getJson("/servicejobs/{$job->id}/export/pdf")->assertForbidden();
        $this->postJson("/servicejobs/{$job->id}/email-pdf")->assertForbidden();
        $this->postJson("/servicejobs/{$job->id}/clearcompletedon")->assertForbidden();
        $this->postJson("/servicejobs/{$job->id}/add-missing-instances")->assertForbidden();
        $this->patchJson("/servicejobs/{$job->id}", ['outcome' => ServiceJobOutcomes::afkeur->value, 'completed_on' => '2026-11-02'])
            ->assertForbidden();
        $this->postJson("/servicejobs/{$job->id}/bulk-complete", ['outcome' => ServiceJobOutcomes::afkeur->value, 'completed_on' => '2026-11-02'])
            ->assertForbidden();
        $this->putJson("/servicecheckinstances/{$check->id}", ['switch_state' => true])->assertForbidden();
        $this->postJson('/servicejobs', ['service_order_id' => $job->service_order_id, 'asset_id' => $job->asset_id, 'outcome' => ServiceJobOutcomes::nog_geen_uitkomst->value])
            ->assertForbidden();
        $this->deleteJson("/servicejobs/{$job->id}")->assertForbidden();

        Mail::assertNothingSent();
        $job->refresh();
        $this->assertNotNull($job->completed_on);
        $this->assertSame(ServiceJobOutcomes::goedkeur->value, $job->outcome);
        $this->assertNull($check->refresh()->switch_state);
    }

    public function test_a_keuring_on_ones_own_werkbon_can_be_opened_exported_mailed_changed_and_removed(): void
    {
        $monteur = $this->userWithPermissions(...self::MONTEUR);
        $job = $this->job($monteur);
        $check = $job->checkInstances()->firstOrFail();

        $this->actingAs($monteur);
        $this->get("/servicejobs/{$job->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('ServiceJob/ShowPage')->where('can_update', true));
        $this->get("/servicejobs/{$job->id}/export/pdf")->assertOk();
        $this->post("/servicejobs/{$job->id}/email-pdf")->assertSessionHas('success');
        Mail::assertSent(ServiceJobPdfMail::class);

        $this->post("/servicejobs/{$job->id}/clearcompletedon")->assertSessionHas('success');
        $this->assertNull($job->refresh()->completed_on);

        $this->put("/servicecheckinstances/{$check->id}", ['switch_state' => true])->assertSessionHasNoErrors();
        $this->assertTrue((bool) $check->refresh()->switch_state);

        $this->patch("/servicejobs/{$job->id}", ['outcome' => ServiceJobOutcomes::afkeur->value, 'completed_on' => '2026-11-02'])
            ->assertSessionHasNoErrors();
        $this->assertSame(ServiceJobOutcomes::afkeur->value, $job->refresh()->outcome);

        $this->delete("/servicejobs/{$job->id}")->assertSessionHas('success');
        $this->assertModelMissing($job);
    }

    public function test_reading_keuringen_does_not_give_the_right_to_change_export_or_remove_them(): void
    {
        $reader = $this->userWithPermissions('serviceorder.read', 'servicejob.read');
        $job = $this->job();
        $check = $job->checkInstances()->firstOrFail();

        $this->actingAs($reader);
        $this->get("/servicejobs/{$job->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can_update', false));
        $this->getJson("/servicejobs/{$job->id}/export/pdf")->assertForbidden();
        $this->postJson("/servicejobs/{$job->id}/email-pdf")->assertForbidden();
        $this->postJson("/servicejobs/{$job->id}/clearcompletedon")->assertForbidden();
        $this->putJson("/servicecheckinstances/{$check->id}", ['switch_state' => true])->assertForbidden();
        $this->deleteJson("/servicejobs/{$job->id}")->assertForbidden();

        $this->assertNotNull($job->refresh()->completed_on);
        $this->assertModelExists($job);
    }

    public function test_the_right_to_read_werkbonnen_without_the_right_to_read_keuringen_opens_none(): void
    {
        $user = $this->userWithPermissions('serviceorder.read', 'servicejob.update', 'servicejob.export_pdf');
        $job = $this->job();

        $this->actingAs($user);
        $this->getJson("/servicejobs/{$job->id}")->assertForbidden();
        $this->getJson("/servicejobs/{$job->id}/export/pdf")->assertForbidden();
        $this->postJson("/servicejobs/{$job->id}/clearcompletedon")->assertForbidden();
    }

    public function test_the_pdf_preview_opens_for_whoever_may_only_mail_it(): void
    {
        $user = $this->userWithPermissions('serviceorder.read', 'servicejob.read', 'servicejob.mail_pdf');
        $job = $this->job();

        $this->actingAs($user)->get("/servicejobs/{$job->id}/export/pdf")->assertOk();
    }

    public function test_a_keuring_is_only_added_to_a_werkbon_named_by_an_id_that_exists(): void
    {
        $user = $this->userWithPermissions('serviceorder.read', 'servicejob.create');
        $job = $this->job();
        $keuring = ['asset_id' => $job->asset_id, 'outcome' => ServiceJobOutcomes::nog_geen_uitkomst->value];

        $this->actingAs($user);
        $this->postJson('/servicejobs', ['service_order_id' => $job->service_order_id + 1000, ...$keuring])->assertForbidden();
        $this->postJson('/servicejobs', ['service_order_id' => $job->service_order_id . 'x', ...$keuring])->assertForbidden();
        $this->postJson('/servicejobs', ['service_order_id' => [$job->service_order_id], ...$keuring])->assertForbidden();
        $this->postJson('/servicejobs', $keuring)->assertForbidden();

        $this->assertSame(1, ServiceJob::count());
    }

    /**
     * Photos and remarks on a keurpunt are part of filling in its keuring, so the
     * rights on photos alone do not open a keuring one may only read: not by
     * upload, remark, removal or the bakje.
     */
    public function test_photos_and_remarks_on_a_keurpunt_of_a_keuring_one_may_only_read_stay_as_they_are(): void
    {
        Storage::fake('public');
        $reader = $this->userWithPermissions(...self::READER_WITH_PHOTO_RIGHTS);
        $job = $this->job();
        $check = $job->checkInstances()->firstOrFail();
        $photo = $this->photoOn($check);
        $on_the_tray = $this->onTheTray($reader, $this->photoOn($job->serviceOrder));

        $this->actingAs($reader);
        $this->postJson('/images', $this->ontoTheKeurpunt($check, ['images' => [UploadedFile::fake()->image('foto.jpg')]]))
            ->assertForbidden();
        $this->postJson('/remarks', ['content' => 'Lekt', 'remarkable_type' => ServiceCheckInstance::class, 'remarkable_id' => $check->id, 'user_id' => $reader->id])
            ->assertForbidden();
        $this->deleteJson("/images/{$photo->id}")->assertForbidden();
        $this->postJson('/api/tray/paste', $this->pasteOntoTheKeurpunt($on_the_tray, $check))
            ->assertUnprocessable()
            ->assertJson(['message' => "Je mag hier geen foto's toevoegen."]);

        $this->assertSame([$photo->id], $check->images()->pluck('images.id')->all());
        $this->assertSame(0, $check->remarks()->count());
    }

    public function test_whoever_may_fill_in_the_keuring_places_and_removes_photos_and_remarks_on_its_keurpunten(): void
    {
        Storage::fake('public');
        $filler = $this->userWithPermissions(...self::READER_WITH_PHOTO_RIGHTS, ...['servicejob.update']);
        $job = $this->job();
        $check = $job->checkInstances()->firstOrFail();
        $photo = $this->photoOn($check);
        $on_the_tray = $this->onTheTray($filler, $this->photoOn($job->serviceOrder));

        $this->actingAs($filler);
        $this->postJson('/images', $this->ontoTheKeurpunt($check, ['images' => [UploadedFile::fake()->image('foto.jpg')]]))
            ->assertCreated();
        $this->postJson('/remarks', ['content' => 'Lekt', 'remarkable_type' => ServiceCheckInstance::class, 'remarkable_id' => $check->id, 'user_id' => $filler->id])
            ->assertCreated();
        $this->deleteJson("/images/{$photo->id}")->assertOk();
        $this->postJson('/api/tray/paste', [...$this->pasteOntoTheKeurpunt($on_the_tray, $check), 'answer' => 'copy'])
            ->assertOk();

        $this->assertModelMissing($photo);
        $this->assertSame(2, $check->images()->count());
        $this->assertSame(1, $check->remarks()->count());
    }

    private function ontoTheKeurpunt(ServiceCheckInstance $check, array $data): array
    {
        return ['imageable_type' => ServiceCheckInstance::class, 'imageable_id' => $check->id, ...$data];
    }

    private function onTheTray(User $user, Image $image): TrayItem
    {
        return TrayItem::create(['user_id' => $user->id, 'trayable_type' => Image::class, 'trayable_id' => $image->id, 'position' => 1]);
    }

    private function pasteOntoTheKeurpunt(TrayItem $item, ServiceCheckInstance $check): array
    {
        return ['item_ids' => [$item->id], 'target' => ['type' => 'photos', 'record' => ServiceCheckInstance::class, 'id' => $check->id]];
    }
}
