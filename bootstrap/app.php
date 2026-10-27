<?php

use App\Exceptions\Refusal;
use App\Http\Middleware\EnsureTenantHasModule;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\InitializeTenancyBySession;
use App\Http\Middleware\ResolveAccessToken;
use App\Http\Middleware\UseLandlordGuard;
use App\Support\DatabaseErrorMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->group(base_path('routes/landlord.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            InitializeTenancyBySession::class,
            HandleInertiaRequests::class,
        ]);

        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            InitializeTenancyBySession::class,
            UseLandlordGuard::class,
            ShareErrorsFromSession::class,
            AuthenticatesRequests::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesSessions::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'accesstoken' => ResolveAccessToken::class,
            'tenant.module' => EnsureTenantHasModule::class,
        ]);
        /**
         * A guest on the admin panel belongs at the panel's login screen, not
         * at the app's: Authenticate sends to the route 'login' by default,
         * whichever guard stopped them.
         */
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('beheer', 'beheer/*')
            ? route('landlord.login')
            : route('login'));

        $middleware->statefulApi();

        $middleware->validateCsrfTokens(except: ['google/webhook']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /**
         * A refusal with a readable reason goes back to the screen with that
         * reason. Without this it became a 500 and the user read "Er is een
         * serverfout opgetreden", while the explanation had already been
         * written by whoever threw the refusal.
         */
        $exceptions->render(function (Refusal $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->withInput()->with('error', $e->getMessage());
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            $message = $e->getMessage() && $e->getMessage() !== 'This action is unauthorized.'
                ? $e->getMessage()
                : 'U heeft geen toestemming om deze actie uit te voeren.';

            return redirect()->back()->with('error', $message);
        });

        /**
         * Safety net for constraint violations: a duplicate unique value, a
         * foreign key that no longer resolves, a NOT NULL column left empty.
         * Validation should catch these first, but every table has indexes no
         * form request knows about, so they end as a field error and a
         * notification here instead of as a 500 in the user's face.
         *
         * Only for requests that write. A GET that the database refuses is a bug
         * in the route and keeps its 500 — and sending a page load back to where
         * it came from is how a reload turns into a redirect loop.
         */
        $exceptions->render(function (QueryException $e, Request $request) {
            if ($request->isMethodSafe()) {
                return null;
            }

            $error = DatabaseErrorMessage::for($e);

            if (!$error) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $error->message,
                    'errors' => $error->field ? [$error->field => [$error->message]] : [],
                ], 422);
            }

            $response = back()->with('error', $error->message);

            return $error->field
                ? $response->withErrors([$error->field => $error->message])
                : $response;
        });

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            /**
             * A request passing here ends as a redirect with a message. If
             * someone does not see that message -- a template not showing the
             * key, a page from cache -- then to all appearances nothing happens:
             * no error, no line, no result. Hence a line here, so it is always
             * written down somewhere.
             */
            if (in_array($response->getStatusCode(), [403, 419], true) && !$request->expectsJson()) {
                Log::warning('Verzoek geweigerd', [
                    'status' => $response->getStatusCode(),
                    'methode' => $request->method(),
                    'pad' => $request->path(),
                ]);
            }

            if ($response->getStatusCode() === 403 && !$request->expectsJson()) {
                return redirect()->back()->with('error', 'U heeft geen toestemming om deze actie uit te voeren.');
            }

            if ($response->getStatusCode() === 419) {
                return back()->with([
                    'message' => 'De pagina is verlopen, ververs de pagina en probeer het nogmaals.',
                ]);
            }

            /**
             * Only for requests that change something. Sending a page that
             * breaks itself back to where it came from is that page again: the
             * browser then keeps bouncing back and forth until it gives up, and
             * nothing of the error is left to see. That is exactly what
             * happened when a half created customer tripped the admin panel.
             */
            $notProd = app()->environment(['local', 'development', 'testing']);
            if (!$notProd && !$request->isMethodSafe() && in_array($response->getStatusCode(), [500, 503, 404])) {
                $messages = [
                    500 => 'Er is een serverfout opgetreden. Probeer het later opnieuw.',
                    503 => 'De service is momenteel niet beschikbaar.',
                    404 => 'De pagina die u zoekt, is niet gevonden.',
                ];

                $status = $response->getStatusCode();
                $message = $messages[$status] ?? 'Er is een onbekende fout opgetreden.';

                return redirect()->back()->with('error', $message);
            }

            return $response;
        });
    })->create();
