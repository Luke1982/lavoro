<?php

namespace Tests\Feature\ReadRules;

use App\Domain\Tray\Tray;
use App\Enums\ShareAccess;
use App\Exceptions\Refusal;
use App\Models\Customer;
use App\Models\Image;
use App\Models\Material;
use App\Models\Project;
use App\Models\ServiceOrder;
use App\Models\Share;
use App\Models\User;
use Tests\Concerns\AttachesPhotos;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Moving a photo off the bakje takes it away where it lives, so only whoever may
 * read the photos there is offered it. A project photo seen only through a werkbon
 * it is shared on is copied: moving it would take it off the project and every
 * werkbon with it, and nobody could put it back.
 */
class TrayMovePhotoRuleTest extends TestCase
{
    use AttachesPhotos;
    use CreatesAuthenticatedUsers;

    private const BINNENDIENST = ['serviceorder.read', 'image.upload', 'image.delete'];

    private Customer $customer;

    private Project $project;

    private Image $shared;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create();
        $this->project = Project::create([
            'title' => 'Nieuwbouw',
            'customer_id' => $this->customer->id,
            'project_manager_id' => User::factory()->create()->id,
            'status' => 'Niet gestart',
        ]);
        $this->shared = $this->photoOn($this->project, pivot: ['main' => false]);
        Share::create([
            'shareable_type' => Image::class,
            'shareable_id' => $this->shared->id,
            'source_type' => Project::class,
            'source_id' => $this->project->id,
            'access' => ShareAccess::View,
        ]);
        ServiceOrder::factory()->create(['customer_id' => $this->customer->id, 'project_id' => $this->project->id]);
    }

    private function paste(User $user, Image $image, ServiceOrder $target, ?string $answer = null): array
    {
        $tray = app(Tray::class);
        $tray->place($user, 'image', [$image->id]);
        $item = $user->trayItems()->where('trayable_id', $image->id)->firstOrFail();

        return $tray->paste($user, [$item->id], [
            'type' => 'photos',
            'record' => ServiceOrder::class,
            'id' => $target->id,
            'internal' => false,
        ], $answer, false);
    }

    public function test_a_shared_project_photo_is_only_copied_by_whoever_may_not_read_the_project(): void
    {
        $binnendienst = $this->userWithPermissions(...self::BINNENDIENST);
        $target = ServiceOrder::factory()->create(['customer_id' => $this->customer->id]);

        $result = $this->paste($binnendienst, $this->shared, $target);

        $this->assertTrue($result['done'] ?? false, 'asked to copy or move a photo he may only copy');
        $this->assertTrue($this->shared->isAttachedTo(Project::class, $this->project->id), 'the photo left the project');
        $this->assertTrue($this->shared->isSharedFrom($this->project), 'the photo left the werkbonnen of the project');
        $this->assertSame(1, $target->images()->count());
    }

    public function test_a_move_he_was_not_offered_is_refused(): void
    {
        $binnendienst = $this->userWithPermissions(...self::BINNENDIENST);
        $target = ServiceOrder::factory()->create(['customer_id' => $this->customer->id]);

        try {
            $this->paste($binnendienst, $this->shared, $target, 'move');
            $this->fail('the photo was moved off a project he may not read');
        } catch (Refusal) {
        }

        $this->assertTrue($this->shared->isAttachedTo(Project::class, $this->project->id));
        $this->assertSame(0, $target->images()->count());
    }

    public function test_whoever_may_read_the_project_is_told_the_move_takes_it_off_its_werkbonnen(): void
    {
        $manager = $this->userWithPermissions('project.read', ...self::BINNENDIENST);
        $target = ServiceOrder::factory()->create(['customer_id' => $this->customer->id]);

        $question = $this->paste($manager, $this->shared, $target)['question'] ?? null;

        $this->assertNotNull($question, 'whoever may read the project was not offered the move');
        $move = collect($question->choices)->firstWhere('value', 'move');
        $this->assertStringContainsString(Image::placeOf($this->project, false), $move->description);
        $this->assertStringContainsString('niet meer op de werkbonnen van het project', $move->description);
    }

    /** The bakje takes a photo wherever the screen's upload does: on a werkbon he is assigned to, without the right to read werkbonnen. */
    public function test_a_photo_is_pasted_where_the_screen_would_take_an_upload(): void
    {
        $monteur = $this->userWithPermissions('material.read', 'image.upload');
        $order = ServiceOrder::factory()->create(['customer_id' => $this->customer->id]);
        $order->syncExecutingUsers([$monteur->id]);
        $photo = $this->photoOn(Material::create(['name' => 'Filter F7', 'price' => 5]), pivot: ['main' => false]);

        $this->assertTrue(Image::mayUploadOn($monteur, $order));

        $result = $this->paste($monteur, $photo, $order);

        $this->assertTrue($result['done'] ?? false);
        $this->assertSame(1, $order->images()->count());
    }
}
