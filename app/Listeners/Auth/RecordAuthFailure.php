<?php

namespace App\Listeners\Auth;

use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Every refused login on its own line, in a file fail2ban reads.
 *
 * Both doors come through here: the customers' own login and the panel at
 * /beheer, because both go through the guard's attempt(). Nothing else is in
 * this file, so a line in it means exactly one thing.
 *
 * The line ends with the address it came from, which is what fail2ban matches:
 *
 *     [2026-09-24 07:12:44] production.WARNING: Failed login guard=web email="x@y.nl" ip=203.0.113.9
 *
 * The filter that reads it is in scripts/tenancy/setup-fail2ban.sh, and the
 * test holds that regular expression against a real line.
 */
class RecordAuthFailure
{
    /**
     * What may appear in the address we write down.
     *
     * Not decoration: the address comes from whoever is typing. Leave a newline
     * in it and they can write their own lines in this file -- a forged "failed
     * login" naming an address of their choosing, and fail2ban bans whoever
     * they point at. So the value is stripped to what an address can contain,
     * and cut off long before a line can be padded into something else.
     */
    private const SAFE = '/[^A-Za-z0-9._%+\-@]/';

    public function failed(Failed $event): void
    {
        $this->write('Failed login', $event->guard, $event->credentials['email'] ?? null, request()->ip());
    }

    /**
     * The throttle answers 429 without an event of its own, so the limiter in
     * AppServiceProvider hands the refused request here. A blocked attempt is
     * the strongest signal in the file: somebody kept going.
     */
    public function blocked(Request $request): void
    {
        $guard = str_starts_with(trim($request->path(), '/'), 'beheer') ? 'landlord' : 'web';

        $this->write('Login blocked after too many attempts', $guard, $request->input('email'), $request->ip());
    }

    private function write(string $what, ?string $guard, mixed $email, ?string $ip): void
    {
        Log::channel('auth')->warning(sprintf('%s guard=%s email="%s" ip=%s',
            $what,
            preg_replace(self::SAFE, '', (string) $guard) ?: 'unknown',
            mb_substr(preg_replace(self::SAFE, '', is_string($email) ? $email : ''), 0, 120),
            $ip ?: 'unknown',
        ));
    }
}
