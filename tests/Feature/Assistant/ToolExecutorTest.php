<?php

namespace Tests\Feature\Assistant;

use App\Domain\Tools\ConfirmationToken;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolRegistry;
use App\Domain\Tools\ToolResult;
use App\Models\AssistantToolCall;
use App\Models\User;
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

    /**
     * The fakes record what happened on themselves, and PHP keeps statics for the
     * whole run. Left alone, whether "it did not execute" holds depends on which
     * test went first.
     */
    protected function setUp(): void
    {
        parent::setUp();

        FakeTool::$executed = false;
        FakeTool::$ran_with = [];
        ConfirmingTool::$executed = false;
        ConfirmingTool::$ran_with = [];
    }

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

    /**
     * A tool that breaks becomes an error the model can act on, and what actually
     * broke stays in the log. A driver speaks in table and column names, and that
     * message travels to the model, which relays it to whoever asked when a boiler
     * was last serviced.
     */
    public function test_a_tool_that_throws_becomes_a_failed_result(): void
    {
        $result = $this->register(new ExplodingTool)->run($this->toolCall(ExplodingTool::name(), $this->admin()));

        $this->assertTrue($result->is_error);
        $this->assertStringNotContainsString('kapot', $result->toModelContent(), 'the innards came out with it');
        $this->assertStringContainsString('vast', $result->toModelContent());
        $this->assertSame('error', AssistantToolCall::sole()->outcome);
    }

    /**
     * Confirmation is declared by a tool but enforced here. Until the flow that
     * issues tokens exists, a tool wanting one must not run at all.
     */
    /**
     * Not an error: nothing went wrong, it is waiting to be allowed. Reported as
     * a failure the model would try to work around it, which is the opposite of
     * what a confirmation is for.
     */
    public function test_a_tool_requiring_confirmation_asks_instead_of_running(): void
    {
        $result = $this->register(new ConfirmingTool)->run($this->toolCall(ConfirmingTool::name(), $this->admin()));

        $this->assertFalse($result->is_error);
        $this->assertSame('bevestiging_nodig', $result->content['status']);
        $this->assertNotEmpty($result->content['confirmation_token']);
        $this->assertFalse(ConfirmingTool::$executed, 'a tool ran before it was confirmed');
        $this->assertSame('confirmation_required', AssistantToolCall::sole()->outcome);
    }

    public function test_a_confirmed_tool_runs(): void
    {
        $user = $this->admin();
        $executor = $this->register(new ConfirmingTool);

        $token = ConfirmationToken::for(ConfirmingTool::name(), ['x' => 1], $user)->encoded();

        $executor->run(new ToolCall(ConfirmingTool::name(), ['x' => 1], $user, confirmation_token: $token));

        $this->assertTrue(ConfirmingTool::$executed);
    }

    /**
     * The approval is the thing that decides what happens. Somebody agreed to one
     * set of arguments; a second attempt carrying different ones must not be able
     * to ride in on that agreement.
     */
    public function test_what_runs_is_what_was_agreed_to_not_what_was_sent(): void
    {
        $user = $this->admin();
        $executor = $this->register(new ConfirmingTool);

        $token = ConfirmationToken::for(ConfirmingTool::name(), ['bedrag' => 10], $user)->encoded();

        $executor->run(new ToolCall(ConfirmingTool::name(), ['bedrag' => 99999], $user, confirmation_token: $token));

        $this->assertSame(['bedrag' => 10], ConfirmingTool::$ran_with);
    }

    public function test_an_approval_belonging_to_someone_else_is_worthless(): void
    {
        $executor = $this->register(new ConfirmingTool);

        $token = ConfirmationToken::for(ConfirmingTool::name(), [], $this->admin())->encoded();
        $result = $executor->run(new ToolCall(
            ConfirmingTool::name(), [], $this->userWith('event.create'), confirmation_token: $token
        ));

        $this->assertFalse(ConfirmingTool::$executed, 'one person confirmed and another one got the action');
        $this->assertSame('bevestiging_nodig', $result->content['status']);
    }

    public function test_an_approval_for_a_different_tool_is_worthless(): void
    {
        $user = $this->admin();
        $executor = $this->register(new ConfirmingTool);

        $token = ConfirmationToken::for('iets_anders', [], $user)->encoded();
        $executor->run(new ToolCall(ConfirmingTool::name(), [], $user, confirmation_token: $token));

        $this->assertFalse(ConfirmingTool::$executed, 'an approval for one action authorised another');
    }

    public function test_a_made_up_approval_is_worthless(): void
    {
        $user = $this->admin();
        $executor = $this->register(new ConfirmingTool);

        $executor->run(new ToolCall(ConfirmingTool::name(), [], $user, confirmation_token: 'ja hoor'));

        $this->assertFalse(ConfirmingTool::$executed, 'any old string got past the gate');
    }

    /**
     * An approval used to be good for its whole quarter of an hour, so one click
     * arriving twice wrote twice — a double tap before the first answer lands, a
     * retried request, a replay. Three attempts made three records, which is the
     * same fault that once turned one job into two werkbonnen.
     */
    public function test_an_approval_can_only_be_spent_once(): void
    {
        $user = $this->admin();
        $executor = $this->register(new ConfirmingTool);
        $token = ConfirmationToken::for(ConfirmingTool::name(), ['x' => 1], $user)->encoded();

        $first = $executor->run(new ToolCall(ConfirmingTool::name(), ['x' => 1], $user, confirmation_token: $token));
        ConfirmingTool::$executed = false;
        $second = $executor->run(new ToolCall(ConfirmingTool::name(), ['x' => 1], $user, confirmation_token: $token));

        $this->assertFalse($first->is_error);
        $this->assertTrue($second->is_error, 'one approval was spent twice');
        $this->assertFalse(ConfirmingTool::$executed, 'the action ran a second time');
        $this->assertSame('already_confirmed', AssistantToolCall::latest('id')->first()->outcome);
    }

    /**
     * Two clicks landing at the same instant must not both believe they were
     * first, which is why the claim is one atomic write rather than a read
     * followed by a write.
     */
    public function test_two_approvals_racing_do_not_both_win(): void
    {
        $user = $this->admin();
        $token = ConfirmationToken::for('anything', [], $user)->encoded();

        $won = collect(range(1, 5))->filter(fn () => ConfirmationToken::claim($token))->count();

        $this->assertSame(1, $won, 'the same approval was claimed ' . $won . ' times');
    }

    public function test_an_approval_goes_stale(): void
    {
        $user = $this->admin();
        $executor = $this->register(new ConfirmingTool);

        $token = ConfirmationToken::for(ConfirmingTool::name(), [], $user)->encoded();

        $this->travel(16)->minutes();

        $executor->run(new ToolCall(ConfirmingTool::name(), [], $user, confirmation_token: $token));

        $this->assertFalse(ConfirmingTool::$executed, 'a confirmation left in a tab was still good the next day');
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

    /** @var array<string, mixed> What it was actually called with. */
    public static array $ran_with = [];

    public function description(): string
    {
        return 'test';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'x' => ['type' => 'integer'],
                'q' => ['type' => 'string'],
                'bedrag' => ['type' => 'integer'],
            ],
            'additionalProperties' => false,
        ];
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
        static::$ran_with = $call->arguments;

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
