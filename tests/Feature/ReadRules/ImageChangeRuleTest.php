<?php

namespace Tests\Feature\ReadRules;

use App\Models\Customer;
use App\Models\Image;
use App\Models\ServiceOrder;
use Tests\Concerns\AttachesPhotos;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Changing, sharing, annotating and removing a photo take the photo itself to be
 * one this person may open: the rights on photos alone reach no photo on a werkbon
 * that is not theirs.
 */
class ImageChangeRuleTest extends TestCase
{
    use AttachesPhotos;
    use CreatesAuthenticatedUsers;

    private const ABILITIES = ['update', 'share', 'edit', 'delete'];

    public function test_the_photo_rights_reach_only_a_photo_this_person_may_open(): void
    {
        $monteur = $this->userWithPermissions('serviceorder.read_own', 'image.update', 'image.edit', 'image.delete');
        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $image = $this->photoOn($order, pivot: ['internal' => false]);

        $this->assertFalse($monteur->can('view', $image));

        foreach (self::ABILITIES as $ability) {
            $this->assertFalse($monteur->can($ability, $image), $ability . ' on a photo he may not open');
        }

        $this->actingAs($monteur)->deleteJson('/api/images/' . $image->id)->assertForbidden();
        $this->assertNotNull(Image::find($image->id));

        $order->syncExecutingUsers([$monteur->id]);
        $monteur = $monteur->fresh();

        foreach (self::ABILITIES as $ability) {
            $this->assertTrue($monteur->can($ability, $image), $ability . ' on a photo of his own werkbon');
        }
    }
}
