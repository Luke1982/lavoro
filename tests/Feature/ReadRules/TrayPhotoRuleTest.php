<?php

namespace Tests\Feature\ReadRules;

use App\Domain\Tray\Kinds\ImageKind;
use App\Enums\ShareAccess;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Image;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Project;
use App\Models\ServiceCheck;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\Share;
use App\Models\User;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The photos on the bakje are the photos this person may open: ImageKind reads
 * Image::visibleTo, so a keurpunt's photo needs the right to read keuringen and a
 * material's the right to read materials, as on every other screen.
 */
class TrayPhotoRuleTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create();
    }

    private function photo(): Image
    {
        return Image::create(['name' => 'foto.jpg', 'path' => 'uploaded/foto-' . uniqid() . '.jpg']);
    }

    private function keurpuntPhoto(ServiceOrder $order): Image
    {
        $product_type = ProductType::factory()->create(['name' => 'Type ' . uniqid()]);
        $check = ServiceCheck::create(['name' => 'Lekdicht', 'type' => 'boolean', 'order' => 1]);
        $check->productTypes()->attach($product_type->id);
        $asset = Asset::factory()->create([
            'product_id' => Product::factory()->create(['product_type_id' => $product_type->id])->id,
            'customer_id' => $this->customer->id,
        ]);
        $job = ServiceJob::factory()->create(['asset_id' => $asset->id, 'service_order_id' => $order->id]);

        $image = $this->photo();
        $job->checkInstances()->firstOrFail()->images()->attach($image->id, ['main' => false]);

        return $image;
    }

    private function materialPhoto(): Image
    {
        $image = $this->photo();
        Material::create(['name' => 'Filter F7', 'price' => 5])->images()->attach($image->id, ['main' => false]);

        return $image;
    }

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create(['customer_id' => $this->customer->id]);
    }

    /** @return array<int, int> */
    private function onTheTray(User $user, Image ...$images): array
    {
        $ids = array_map(fn (Image $image) => $image->id, $images);
        $tray = ImageKind::visible($ids, $user)->modelKeys();
        $rule = Image::visibleTo($user)->whereIn('images.id', $ids)->pluck('images.id')->all();

        sort($tray);
        sort($rule);
        $this->assertSame($rule, $tray, 'the bakje and Image::visibleTo disagree');

        foreach ($images as $image) {
            $this->assertSame(in_array($image->id, $tray, true), $user->can('view', $image));
        }

        return $tray;
    }

    public function test_a_keurpunt_photo_needs_the_right_to_read_keuringen(): void
    {
        $image = $this->keurpuntPhoto($this->order());

        $this->assertSame([], $this->onTheTray($this->userWithPermissions('serviceorder.read', 'image.see'), $image));
        $this->assertSame([$image->id], $this->onTheTray(
            $this->userWithPermissions('serviceorder.read', 'servicejob.read', 'image.see'),
            $image,
        ));
    }

    public function test_a_keurpunt_photo_on_someone_elses_werkbon_stays_off_the_tray(): void
    {
        $monteur = $this->userWithPermissions('serviceorder.read_own', 'servicejob.read', 'image.see');
        $own = $this->order();
        $own->syncExecutingUsers([$monteur->id]);
        $mine = $this->keurpuntPhoto($own);
        $theirs = $this->keurpuntPhoto($this->order());

        $this->assertSame([$mine->id], $this->onTheTray($monteur, $mine, $theirs));
    }

    public function test_a_material_photo_needs_the_right_to_read_materials(): void
    {
        $image = $this->materialPhoto();

        $this->assertSame([], $this->onTheTray($this->userWithPermissions('image.see'), $image));
        $this->assertSame([$image->id], $this->onTheTray($this->userWithPermissions('material.read', 'image.see'), $image));
    }

    public function test_no_photo_without_the_right_to_see_photos(): void
    {
        $order = $this->order();
        $image = $this->photo();
        $order->images()->attach($image->id, ['internal' => false]);

        $this->assertSame([], $this->onTheTray($this->userWithPermissions('serviceorder.read'), $image));
        $this->assertSame([$image->id], $this->onTheTray($this->userWithPermissions('serviceorder.read', 'image.see'), $image));
    }

    public function test_a_photo_shared_from_a_project_is_read_on_its_werkbonnen_without_the_project(): void
    {
        $project = Project::create([
            'title' => 'Nieuwbouw',
            'customer_id' => $this->customer->id,
            'project_manager_id' => User::factory()->create()->id,
            'status' => 'Niet gestart',
        ]);
        $shared = $this->photo();
        $kept = $this->photo();
        $project->images()->attach([$shared->id => ['main' => false], $kept->id => ['main' => false]]);
        Share::create([
            'shareable_type' => Image::class,
            'shareable_id' => $shared->id,
            'source_type' => Project::class,
            'source_id' => $project->id,
            'access' => ShareAccess::View,
        ]);

        $planner = $this->userWithPermissions('serviceorder.read', 'image.see');
        $this->assertSame([], $this->onTheTray($planner, $shared, $kept), 'no werkbon under the project yet');

        ServiceOrder::factory()->create(['customer_id' => $this->customer->id, 'project_id' => $project->id]);
        $this->assertSame([$shared->id], $this->onTheTray($planner, $shared, $kept));

        $monteur = $this->userWithPermissions('serviceorder.read_own', 'image.see');
        $this->assertSame([], $this->onTheTray($monteur, $shared, $kept), 'not on a werkbon of his');
    }

    public function test_a_shared_project_photo_shows_its_werkbon_to_whoever_may_not_read_projects(): void
    {
        $project = Project::create([
            'title' => 'Nieuwbouw',
            'customer_id' => $this->customer->id,
            'project_manager_id' => User::factory()->create()->id,
            'status' => 'Niet gestart',
        ]);
        $shared = $this->photo();
        $project->images()->attach($shared->id, ['main' => false]);
        Share::create([
            'shareable_type' => Image::class,
            'shareable_id' => $shared->id,
            'source_type' => Project::class,
            'source_id' => $project->id,
            'access' => ShareAccess::View,
        ]);
        $order = ServiceOrder::factory()->create(['customer_id' => $this->customer->id, 'project_id' => $project->id]);

        $planner = $this->userWithPermissions('serviceorder.read', 'image.see');
        $card = ImageKind::describe(ImageKind::visible([$shared->id], $planner)->firstOrFail());
        $this->assertSame('/serviceorders/' . $order->id, $card['url']);
        $this->assertSame(Image::labelOf($order), $card['subtitle']);

        $manager = $this->userWithPermissions('serviceorder.read', 'project.read', 'image.see');
        $card = ImageKind::describe(ImageKind::visible([$shared->id], $manager)->firstOrFail());
        $this->assertSame('/projects/' . $project->id, $card['url']);
        $this->assertSame(Image::labelOf($project), $card['subtitle']);
    }
}
