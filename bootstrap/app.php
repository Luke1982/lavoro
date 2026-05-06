<?php

use Illuminate\Http\Request;
use Illuminate\Foundation\Application;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
            // Safety net: convert MySQL "Numeric value out of range" (SQLSTATE
            // 22003 / errno 1264) into a 422 validation error so users see a
            // meaningful message instead of a 500. The DbRange rule catches
            // this proactively, but this guards against any field we forgot.
            $exceptions->render(function (\Illuminate\Database\QueryException $e, Request $request) {
                $sqlState = $e->getCode();
                $errno    = $e->errorInfo[1] ?? null;
                if ($sqlState !== '22003' && $errno !== 1264) {
                    return null;
                }
                preg_match("/column '([^']+)'/i", $e->getMessage(), $m);
                $field   = $m[1] ?? 'value';
                $message = "De waarde voor '{$field}' is buiten het toegestane bereik.";
                return back()
                    ->withErrors([$field => $message])
                    ->with('error', $message)
                    ->withInput();
            });

            $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
                if (!app()->environment(['local', 'development', 'testing']) && in_array($response->getStatusCode(), [500, 503, 404, 403])) {
                    $messages = [
                        500 => 'Er is een serverfout opgetreden. Probeer het later opnieuw.',
                        503 => 'De service is momenteel niet beschikbaar.',
                        404 => 'De pagina die u zoekt, is niet gevonden.',
                        403 => 'U heeft geen toestemming om deze pagina te bekijken.',
                    ];

                    $status = $response->getStatusCode();
                    $message = $messages[$status] ?? 'Er is een onbekende fout opgetreden.';

                    return redirect()->back()->with('error', $message);
                } elseif ($response->getStatusCode() === 419) {
                    return back()->with([
                        'message' => 'De pagina is verlopen, ververs de pagina en probeer het nogmaals.',
                    ]);
                }

                return $response;
            });
    })->create();
