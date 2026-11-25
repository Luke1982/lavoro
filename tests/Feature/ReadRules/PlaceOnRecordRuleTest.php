<?php

namespace Tests\Feature\ReadRules;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Event;
use App\Models\Image;
use App\Models\Product;
use App\Models\Remark;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Putting a photo, document or remark on a record takes seeing that record the
 * way its own screen does (mayPlaceOn): an appointment this person is on and may
 * give feedback on, a storing whose page they may open.
 */
class PlaceOnRecordRuleTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function remarkOn(User $user, Model $record)
    {
        return $this->actingAs($user)->postJson('/api/remarks', [
            'content' => 'Ziet er goed uit',
            'remarkable_type' => $record::class,
            'remarkable_id' => $record->getKey(),
            'user_id' => $user->id,
        ]);
    }

    private function assertMayPlace(bool $expected, User $user, Model $record): void
    {
        foreach ([Image::class, Document::class, Remark::class] as $attachment) {
            $this->assertSame($expected, $attachment::mayPlaceOn($user, $record), class_basename($attachment));
        }
    }

    public function test_feedback_on_an_appointment_takes_being_on_it(): void
    {
        $user = $this->userWithPermissions('event.provide_feedback', 'image.upload');
        $event = Event::factory()->create();

        $this->assertTrue($user->can('provideFeedback', $event));
        $this->assertMayPlace(false, $user, $event);
        $this->remarkOn($user, $event)->assertForbidden();
        $this->actingAs($user)->postJson('/api/images', [
            'imageable_type' => Event::class,
            'imageable_id' => $event->id,
        ])->assertForbidden();

        $event->syncExecutingUsers([$user->id]);

        $this->assertMayPlace(true, $user, $event);
        $this->remarkOn($user, $event)->assertCreated();
    }

    public function test_a_storing_in_sight_without_its_page_takes_nothing(): void
    {
        $overview = $this->userWithPermissions('ticket.see_all', 'image.upload', 'document.upload');
        $ticket = Ticket::factory()->create(['asset_id' => Asset::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'product_id' => Product::factory()->create()->id,
        ])->id]);

        $this->assertTrue($overview->can('view', $ticket));
        $this->assertMayPlace(false, $overview, $ticket);
        $this->remarkOn($overview, $ticket)->assertForbidden();

        $this->assertMayPlace(true, $this->userWithPermissions('ticket.read'), $ticket);
    }
}
