<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
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
            HandleInertiaRequests::class,
        ]);
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
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

        // Safety net: convert MySQL "Numeric value out of range" (SQLSTATE
        // 22003 / errno 1264) into a 422 validation error so users see a
        // meaningful message instead of a 500. The DbRange rule catches
        // this proactively, but this guards against any field we forgot.
        $exceptions->render(function (QueryException $e, Request $request) {
            $sqlState = $e->getCode();
            $errno = $e->errorInfo[1] ?? null;
            if ($sqlState !== '22003' && $errno !== 1264) {
                return null;
            }
            preg_match("/column '([^']+)'/i", $e->getMessage(), $m);
            $field = $m[1] ?? 'value';
            $message = "De waarde voor '{$field}' is buiten het toegestane bereik.";

            return back()
                ->withErrors([$field => $message])
                ->with('error', $message)
                ->withInput();
        });

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($response->getStatusCode() === 403 && ! $request->expectsJson()) {
                return redirect()->back()->with('error', 'U heeft geen toestemming om deze actie uit te voeren.');
            }

            if ($response->getStatusCode() === 419) {
                return back()->with([
                    'message' => 'De pagina is verlopen, ververs de pagina en probeer het nogmaals.',
                ]);
            }

            $notProd = app()->environment(['local', 'development', 'testing']);
            if (! $notProd && in_array($response->getStatusCode(), [500, 503, 404])) {
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
