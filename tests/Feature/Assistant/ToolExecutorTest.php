<?php

namespace Tests\Feature\Assistant;

use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolRegistry;
use App\Domain\Tools\ToolResult;
use App\Models\AssistantToolCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The executor is the only door to a tool, which makes it the only place the
 * three guarantees can be checked: the caller was allowed, the attempt was
 * recorded, and nothing a tool does escapes as an exception.
 */
class ToolExecutorTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private function register(Tool ...$tools): ToolExecutor
    {
        $classes = array_map(fn (Tool $tool) => get_class($tool), $tools);

        $this->app->instance(ToolRegistry::class, new ToolRegistry($classes));

        return $this->app->make(ToolExecutor::class);
    }

    private function toolCall(string $name, User $user, array $arguments = []): ToolCall
    {
        return new ToolCall($name, $arguments, $user);
    }

    public function test_an_unknown_tool_fails_and_is_recorded_rather_than_throwing(): void
    {
        $result = $this->register()->run($this->toolCall('does_not_exist', $this->admin()));

        $this->assertTrue($result->is_error);
        $this->assertSame('unknown_tool', AssistantToolCall::sole()->outcome);
    }

    public function test_a_refused_tool_never_executes_and_is_recorded_as_denied(): void
    {
        $tool = new RefusingTool;
        $result = $this->register($tool)->run($this->toolCall(RefusingTool::name(), $this->admin()));

        $this->assertTrue($result->is_error);
        $this->assertFalse(RefusingTool::$executed, 'a denied tool was executed anyway');
        $this->assertSame('denied', AssistantToolCall::sole()->outcome);
    }

    /**
     * An authorisation check that errored has not said yes, and one broken policy
     * must not take down the whole turn.
     */
    public function test_a_policy_that_throws_denies_instead_of_escaping(): void
    {
        $result = $this->register(new ThrowingTool)->run($this->toolCall(ThrowingTool::name(), $this->admin()));

        $this->assertTrue($result->is_error);
        $this->assertSame('denied', AssistantToolCall::sole()->outcome);
    }

    public function test_a_tool_that_throws_becomes_a_failed_result(): void
    {
        $result = $this->register(new ExplodingTool)->run($this->toolCall(ExplodingTool::name(), $this->admin()));

        $this->assertTrue($result->is_error);
        $this->assertStringContainsString('kapot', $result->toModelContent());
        $this->assertSame('error', AssistantToolCall::sole()->outcome);
    }

    /**
     * Confirmation is declared by a tool but enforced here. Until the flow that
     * issues tokens exists, a tool wanting one must not run at all.
     */
    public function test_a_tool_requiring_confirmation_refuses_without_one(): void
    {
        $result = $this->register(new ConfirmingTool)->run($this->toolCall(ConfirmingTool::name(), $this->admin()));

        $this->assertTrue($result->is_error);
        $this->assertFalse(ConfirmingTool::$executed, 'a tool ran before it was confirmed');
        $this->assertSame('confirmation_required', AssistantToolCall::sole()->outcome);
    }

    public function test_a_successful_call_records_its_arguments_and_outcome(): void
    {
        $user = $this->admin();
        $result = $this->register(new PermissiveTool)->run(
            $this->toolCall(PermissiveTool::name(), $user, ['q' => 'hallo'])
        );

        $this->assertFalse($result->is_error);

        $recorded = AssistantToolCall::sole();
        $this->assertSame('ok', $recorded->outcome);
        $this->assertSame(PermissiveTool::name(), $recorded->tool);
        $this->assertSame($user->id, $recorded->user_id);
        $this->assertSame(['q' => 'hallo'], $recorded->arguments);
    }
}

abstract class FakeTool implements Tool
{
    public static bool $executed = false;

    public function description(): string
    {
        return 'test';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return true;
    }

    public static function difficulty(): int
    {
        return 1;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public function execute(ToolCall $call): ToolResult
    {
        static::$executed = true;

        return ToolResult::ok(['ok' => true]);
    }

    public static function availableTo(): array
    {
        return ToolProfile::all();
    }
}

class PermissiveTool extends FakeTool
{
    public static function name(): string
    {
        return 'permissive';
    }
}

class RefusingTool extends FakeTool
{
    public static bool $executed = false;

    public static function name(): string
    {
        return 'refusing';
    }

    public function authorize(User $user, array $arguments): bool
    {
        return false;
    }
}

class ThrowingTool extends FakeTool
{
    public static function name(): string
    {
        return 'throwing';
    }

    public function authorize(User $user, array $arguments): bool
    {
        throw new RuntimeException('policy kapot');
    }
}

class ExplodingTool extends FakeTool
{
    public static function name(): string
    {
        return 'exploding';
    }

    public function execute(ToolCall $call): ToolResult
    {
        throw new RuntimeException('tool kapot');
    }
}

class ConfirmingTool extends FakeTool
{
    public static bool $executed = false;

    public static function name(): string
    {
        return 'confirming';
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }
}
