<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventFeedbackApiTest extends TestCase
{
    use RefreshDatabase;

    private function feedback_user(): User
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'planner']);
        foreach (['events.provide_feedback', 'image.upload', 'image.see', 'image.delete'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission->id);
        }
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_remark_can_be_posted_to_event_via_json(): void
    {
        $user = $this->feedback_user();
        $event = Event::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/remarks', [
            'content' => 'Ziet er goed uit',
            'remarkable_type' => 'App\\Models\\Event',
            'remarkable_id' => $event->id,
            'user_id' => $user->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('content', 'Ziet er goed uit');
        $this->assertCount(1, $event->fresh()->remarks);
    }

    public function test_feedback_endpoint_returns_remarks_and_images(): void
    {
        $user = $this->feedback_user();
        $event = Event::factory()->create();

        $response = $this->actingAs($user)->getJson("/api/events/{$event->id}/feedback");

        $response->assertOk();
        $response->assertJsonStructure(['remarks', 'images']);
    }

    public function test_feedback_endpoint_forbidden_without_permission(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $this->actingAs($user)->getJson("/api/events/{$event->id}/feedback")->assertForbidden();
    }

    public function test_remark_post_forbidden_without_permission(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $this->actingAs($user)->postJson('/api/remarks', [
            'content' => 'x',
            'remarkable_type' => 'App\\Models\\Event',
            'remarkable_id' => $event->id,
            'user_id' => $user->id,
        ])->assertForbidden();
    }

    public function test_image_post_forbidden_without_permission(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $this->actingAs($user)->postJson('/api/images', [
            'imageable_type' => 'App\\Models\\Event',
            'imageable_id' => $event->id,
        ])->assertForbidden();
    }
}
