<?php

namespace App\Http\Controllers;

use App\Domain\Integrations\Connectors;
use App\Domain\Integrations\Contracts\ConnectsWithOAuth;
use App\Exceptions\Refusal;
use App\Http\Requests\Integrations\IntegrationOAuthRequest;
use App\Models\IntegrationConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

/**
 * "Koppelen met Exact": off to the other system to log in and allow Lavoro
 * in, and back here with a code that becomes the connection's tokens.
 *
 * One return address for every system and every customer, which is the
 * address registered with the other side. The customer comes from the
 * session of whoever started it -- nothing in the return call names it -- and
 * the state, kept in that same session, is what proves the call belongs to
 * that start. Starting changes nothing; the connection is made or updated only
 * when the login came back complete.
 */
class IntegrationOAuthController extends Controller
{
    private const SESSION_KEY = 'integration_oauth';

    public function start(IntegrationOAuthRequest $request, string $connector): RedirectResponse
    {
        $class = Connectors::exists($connector) ? Connectors::classFor($connector) : abort(404);

        if (!$class::connectsWithOAuth()) {
            abort(404);
        }

        if (!$class::oauthReady()) {
            throw new Refusal('De app voor ' . $class::label() . ' is nog niet geregistreerd. Vraag MajorLabel om dat te doen.');
        }

        $state = Str::random(40);
        $request->session()->put(self::SESSION_KEY . '.' . $state, [
            'connector' => $connector,
            'tenant' => tenancy()->tenant->getTenantKey(),
        ]);

        /** @var ConnectsWithOAuth $oauth */
        $oauth = $this->connection($connector)->connector();

        return redirect()->away($oauth->authorizationUrl(route('integrations.oauth.callback'), $state));
    }

    public function callback(IntegrationOAuthRequest $request): RedirectResponse
    {
        $state = (string) $request->validated('state');
        $started = ctype_alnum($state) ? $request->session()->pull(self::SESSION_KEY . '.' . $state) : null;

        if (!$started || $started['tenant'] !== tenancy()->tenant->getTenantKey()) {
            return redirect()->route('integrations.index')->with('error', 'Het koppelen is niet afgemaakt: dit antwoord hoort niet bij een koppeling die hier is gestart. Probeer het opnieuw.');
        }

        $connection = $this->connection($started['connector']);

        if (filled($request->validated('error')) || !filled($request->validated('code'))) {
            return redirect()->route('integrations.index')->with('error', $connection->label() . ' gaf geen toestemming'
                . (filled($request->validated('error_description')) ? ': ' . $request->validated('error_description') : '.'));
        }

        /**
         * A refusal is shown here, not handed to the usual "back with a
         * message": back would be the other system's login page.
         */
        try {
            /** @var ConnectsWithOAuth $oauth */
            $oauth = $connection->connector();
            $result = $oauth->completeAuthorization($request->validated('code'), route('integrations.oauth.callback'));
        } catch (Refusal $e) {
            return redirect()->route('integrations.index')->with('error', $e->getMessage());
        }

        $connection->save();

        return redirect()->route('integrations.index')->with('success', $connection->label() . ' is gekoppeld. ' . $result);
    }

    private function connection(string $connector): IntegrationConnection
    {
        return IntegrationConnection::where('connector', $connector)->first() ?? IntegrationConnection::open($connector);
    }
}
