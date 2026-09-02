<?php

use App\Http\Middleware\EnsureTenantHasModule;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\InitializeTenancyBySession;
use App\Http\Middleware\InitializeTenancyForApi;
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
            'tenant.api' => InitializeTenancyForApi::class,
            'tenant.module' => EnsureTenantHasModule::class,
        ]);
        /**
         * Een gast op het beheerpaneel hoort naar het inlogscherm van het
         * paneel, niet naar dat van de app: Authenticate stuurt standaard naar
         * de route 'login', ongeacht welke guard hem tegenhield.
         */
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('beheer', 'beheer/*')
            ? route('landlord.login')
            : route('login'));

        $middleware->statefulApi();

        $middleware->validateCsrfTokens(except: ['google/webhook']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
             * Een verzoek dat hier langskomt eindigt als een omleiding met een
             * melding erbij. Ziet iemand die melding niet -- een sjabloon dat
             * de sleutel niet toont, een pagina uit de cache -- dan gebeurt er
             * ogenschijnlijk niets: geen fout, geen regel, geen resultaat.
             * Daarom hier wel een regel, zodat het altijd ergens staat.
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
             * Alleen voor verzoeken die iets wijzigen. Een pagina die zelf
             * stukloopt terugsturen naar waar hij vandaan kwam is die pagina
             * opnieuw: dan blijft de browser heen en weer springen tot hij het
             * opgeeft, en is er van de fout niets meer te zien. Precies dat
             * gebeurde toen een half aangemaakte klant het beheerpaneel liet
             * struikelen.
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
