<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveAccessToken;
use App\Support\DatabaseErrorMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\InitializeTenancyBySession::class,
            HandleInertiaRequests::class,
        ]);

        $middleware->priority([
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \App\Http\Middleware\InitializeTenancyBySession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
            \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'accesstoken' => ResolveAccessToken::class,
        ]);
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
            if ($response->getStatusCode() === 403 && !$request->expectsJson()) {
                return redirect()->back()->with('error', 'U heeft geen toestemming om deze actie uit te voeren.');
            }

            if ($response->getStatusCode() === 419) {
                return back()->with([
                    'message' => 'De pagina is verlopen, ververs de pagina en probeer het nogmaals.',
                ]);
            }

            $notProd = app()->environment(['local', 'development', 'testing']);
            if (!$notProd && in_array($response->getStatusCode(), [500, 503, 404])) {
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
