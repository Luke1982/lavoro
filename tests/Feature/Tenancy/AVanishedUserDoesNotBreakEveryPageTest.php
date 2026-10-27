<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Somebody logged in whose user row is gone.
 *
 * That happens when a user is deleted for real, and it happens to everybody at
 * once when a customer's installation is imported again: the database is
 * replaced by the source and whoever was logged in is not in it any more.
 *
 * The guard looks the id up in the customer's database, finds nothing, returns
 * null -- and leaves the id in the session, because only logging out takes it
 * out. After the request the middleware ends tenancy, and then the database
 * session driver asks the guard for that id again so it can store it. That
 * second lookup goes to the central database, which has no users table:
 *
 *   SQLSTATE[42S02]: Base table or view not found: 1146
 *   Table 'lavoro_landlord.users' doesn't exist
 *
 * A 500 on every page for as long as that session lives, the login screen
 * included, so there is no way back in.
 */
class AVanishedUserDoesNotBreakEveryPageTest extends TestCase
{
    /**
     * Logged in the way a person is, not with actingAs(): that hands the guard
     * a user object it keeps, so the id is never looked up again and the
     * failure cannot happen. The session has to carry the id on its own.
     */
    private function logIn(): User
    {
        $user = User::factory()->create(['password' => 'geheimgeheim']);

        $this->post('/login', ['email' => $user->email, 'password' => 'geheimgeheim'])
            ->assertRedirect();

        $this->assertSame($user->id, session($this->sessionKey()), 'logging in puts the id in the session');

        return $user;
    }

    /**
     * On a server every request starts with nothing resolved, and the guard
     * fetches the user out of the database by the id in the session. In one
     * test process the guard is still holding the object it found earlier, so
     * a deleted row would never be noticed. This throws that away.
     */
    private function asANewRequest(): void
    {
        Auth::forgetGuards();
    }

    private function sessionKey(): string
    {
        $guard = Auth::guard();

        $this->assertInstanceOf(SessionGuard::class, $guard);

        return $guard->getName();
    }

    public function test_the_session_lets_go_of_a_user_who_is_no_longer_there(): void
    {
        $user = $this->logIn();

        /** Gone for real, the way a replaced database leaves them. */
        DB::connection('tenant')->table('users')->where('id', $user->id)->delete();

        $this->asANewRequest();
        $this->get('/');

        $this->assertNull(session($this->sessionKey()),
            'the id must be out of the session before the tenant connection closes');
    }

    /**
     * The failure itself, as close as a test can get: with tenancy ended, ask
     * the guard for the id the way the session driver does when it writes.
     */
    public function test_asking_the_guard_afterwards_does_not_reach_the_central_database(): void
    {
        $user = $this->logIn();

        DB::connection('tenant')->table('users')->where('id', $user->id)->delete();

        $this->asANewRequest();
        $this->get('/');

        tenancy()->end();

        $this->assertNull(Auth::guard()->id(),
            'this is what the session driver calls, and it must not go looking in the central database');
    }

    /** The ordinary case has to keep working: a user who is simply there. */
    public function test_a_user_who_still_exists_stays_logged_in(): void
    {
        $user = $this->logIn();

        $this->get('/')->assertSuccessful();

        $this->assertSame($user->id, session($this->sessionKey()),
            'nothing may be forgotten for a user who is still in the database');
    }
}
