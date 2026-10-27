<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Both doors write a refused login to one file, in the shape fail2ban reads.
 *
 * The regular expression below is the one in the filter that
 * scripts/tenancy/setup-fail2ban.sh installs. It is held against a real line
 * here, because the two drifting apart is silent: the file keeps filling and
 * nothing is ever banned.
 */
class RefusedLoginsAreLoggedTest extends TestCase
{
    /** `<HOST>` is fail2ban's own placeholder; it expands to an address matcher. */
    private const FAIL2BAN = '/^\[[^\]]+\] WARNING: (Failed login|Login blocked[^=]*) guard=\S+ email="[^"]*" ip=(?P<host>\S+)$/m';

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = storage_path('framework/testing/auth-' . uniqid() . '.log');
        config(['logging.channels.auth.path' => $this->log]);
    }

    protected function tearDown(): void
    {
        File::delete($this->log);

        parent::tearDown();
    }

    private function lines(): array
    {
        return array_values(array_filter(explode("\n", (string) @file_get_contents($this->log))));
    }

    public function test_a_refused_login_on_the_app_is_written_down(): void
    {
        User::factory()->create(['email' => 'monteur@example.nl']);

        $this->post('/login', ['email' => 'monteur@example.nl', 'password' => 'wrong-one'])
            ->assertRedirect();

        $lines = $this->lines();

        $this->assertCount(1, $lines, 'one refusal, one line');
        $this->assertMatchesRegularExpression(self::FAIL2BAN, $lines[0], 'fail2ban would not match this line');
        $this->assertStringContainsString('guard=web email="monteur@example.nl"', $lines[0]);
    }

    public function test_a_refused_login_on_the_panel_is_written_down(): void
    {
        $this->post('/beheer/login', ['email' => 'beheer@example.nl', 'password' => 'wrong-one']);

        $lines = $this->lines();

        $this->assertCount(1, $lines);
        $this->assertMatchesRegularExpression(self::FAIL2BAN, $lines[0]);
        $this->assertStringContainsString('guard=landlord', $lines[0], 'the panel is a guard of its own');
    }

    /**
     * The address is typed by whoever is trying. A newline in it would let them
     * write their own lines in this file: a refusal naming an address they
     * choose, and fail2ban bans whoever they point at.
     */
    public function test_a_forged_line_cannot_be_smuggled_through_the_address(): void
    {
        /**
         * Straight at the listener: the form refuses an address like this long
         * before a guard sees it, and it is the writing that has to be safe --
         * every other caller of this listener is a guard, not a form.
         */
        event(new Failed('web', null, [
            'email' => "x@y.nl\n[2026-01-01 00:00:00] WARNING: Failed login guard=web email=\"a\" ip=8.8.8.8",
            'password' => 'wrong-one',
        ]));

        $lines = $this->lines();

        $this->assertCount(1, $lines, 'the address must not be able to add lines');
        $this->assertMatchesRegularExpression(self::FAIL2BAN, $lines[0]);

        preg_match(self::FAIL2BAN, $lines[0], $found);

        $this->assertSame('127.0.0.1', $found['host'], 'fail2ban must ban the one who typed it, not 8.8.8.8');
    }

    public function test_guessing_stops_after_five_tries(): void
    {
        foreach (range(1, 6) as $attempt) {
            $response = $this->post('/login', ['email' => 'monteur@example.nl', 'password' => 'wrong-' . $attempt]);
        }

        $response->assertStatus(429);

        $blocked = array_filter($this->lines(), fn (string $line) => str_contains($line, 'Login blocked'));

        $this->assertNotEmpty($blocked, 'a lockout belongs in the file too');
        $this->assertMatchesRegularExpression(self::FAIL2BAN, array_values($blocked)[0]);
    }
}
