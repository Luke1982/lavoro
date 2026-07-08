<?php

namespace Tests\Feature;

use App\Enums\EventTrigger;
use App\Enums\StandardEmailTriggerType;
use App\Models\Event;
use App\Models\StandardEmail;
use App\Services\StandardEmailTriggerResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StandardEmailTriggerResolverTest extends TestCase
{
    use RefreshDatabase;

    private function template_with_trigger(string $trigger, string $trigger_type): StandardEmail
    {
        $email = StandardEmail::create(['name' => 'T', 'subject' => 'S', 'body' => 'B']);
        $email->triggers()->create(['trigger' => $trigger, 'trigger_type' => $trigger_type]);

        return $email;
    }

    public function test_matches_by_trigger_and_type(): void
    {
        $this->template_with_trigger('event_created', 'confirm');
        $this->template_with_trigger('event_created', 'background');
        $this->template_with_trigger('event_updated', 'confirm');

        $matches = StandardEmailTriggerResolver::matching(
            new Event,
            EventTrigger::event_created,
            [StandardEmailTriggerType::confirm->name, StandardEmailTriggerType::allowedit->name]
        );

        $this->assertCount(1, $matches);
        $this->assertEquals('confirm', $matches->first()->trigger_type);
    }

    public function test_excludes_triggers_of_soft_deleted_templates(): void
    {
        $email = $this->template_with_trigger('event_created', 'background');
        $email->delete();

        $matches = StandardEmailTriggerResolver::matching(
            new Event,
            EventTrigger::event_created,
            [StandardEmailTriggerType::background->name]
        );

        $this->assertCount(0, $matches);
    }
}
