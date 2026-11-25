<?php

namespace Tests\Feature\Assistant;

use App\Domain\Tools\ToolCall;
use Tests\TestCase;

/**
 * A wall-clock time is read from its parts and nothing else.
 *
 * Handed whole to the parser, a "Z", an offset or "+2 days" behind the time is
 * taken at its word and moves the appointment; a time the clocks skip is
 * pushed on by an hour. Each of those must be refused, not stored.
 */
class MomentArgumentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.display_timezone' => 'Europe/Amsterdam']);
    }

    public function test_a_local_time_becomes_the_moment_to_store(): void
    {
        $this->assertSame('2026-06-01 06:00:00', ToolCall::parseMoment('2026-06-01 08:00')?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-01 06:00:30', ToolCall::parseMoment('2026-06-01T08:00:30')?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-01 07:00:00', ToolCall::parseMoment('2026-12-01 08:00')?->format('Y-m-d H:i:s'));
    }

    public function test_anything_after_the_time_is_refused_rather_than_obeyed(): void
    {
        $this->assertNull(ToolCall::parseMoment('2026-06-01T08:00:00Z'));
        $this->assertNull(ToolCall::parseMoment('2026-06-01T08:00:00+05:00'));
        $this->assertNull(ToolCall::parseMoment('2026-06-01 08:00+05:00'));
        $this->assertNull(ToolCall::parseMoment('2026-06-01 08:00 +2 days'));
    }

    public function test_a_time_that_does_not_exist_is_refused(): void
    {
        $this->assertNull(ToolCall::parseMoment('2026-03-29 02:30'));
        $this->assertNull(ToolCall::parseMoment('2026-02-30 08:00'));
        $this->assertNull(ToolCall::parseMoment('2026-06-01 24:00'));
        $this->assertNull(ToolCall::parseMoment('2026-06-01 08:60'));
        $this->assertNull(ToolCall::parseMoment('2026-06-01 08:00:61'));
    }
}
