<?php

declare(strict_types=1);

use App\Exceptions\AIProviderException;
use App\Exceptions\VectorStoreException;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Coarse area gate only. Every writable admin resource also needs a
        // Policy — middleware is not authorization (docs/engineering.md §10).
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         | A student must never see a provider name, an upstream status code, or
         | a stack trace (docs/architecture.md §14, PRD §40). Everything a
         | developer needs is logged against a correlation id which is also
         | shown to the user, so a support request can be traced without the
         | error message itself carrying anything sensitive.
         |
         | Logging happens in report(), not render(): report() also fires for
         | queued jobs, where there is no response to render. Returning false
         | suppresses the framework's own log line, which would otherwise
         | duplicate every upstream failure.
         */
        $exceptions->report(function (AIProviderException|VectorStoreException $throwable): bool {
            $correlationId = (string) Str::uuid();

            // Carried to render() below, and attached to every other log entry
            // made while handling this request.
            Context::add('correlation_id', $correlationId);

            Log::error('Upstream service failure', [
                'correlation_id' => $correlationId,
                'exception' => $throwable::class,
                // The message carries the provider name and status; that is
                // exactly why it is logged and not rendered.
                'message' => $throwable->getMessage(),
                'user_id' => request()->user()?->getAuthIdentifier(),
            ]);

            return false;
        });

        $exceptions->render(function (
            AIProviderException|VectorStoreException $throwable,
            Request $request,
        ): Response {
            $correlationId = (string) Context::get('correlation_id', '');

            $message = 'The AI tutor is unavailable right now. Everything else still works — '
                .'try the lesson content or the 3D model while we sort it out.';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'correlation_id' => $correlationId,
                ], Response::HTTP_SERVICE_UNAVAILABLE);
            }

            return back()->with('error', $message);
        });

        /*
         | Render HTTP errors through the Inertia error page so a student sees
         | the app shell and a plain explanation rather than Symfony's debug
         | screen or a bare browser error.
         |
         | Only when debug is off: locally the stack trace is the whole point.
         */
        $exceptions->respond(function (Response $response, Throwable $throwable, Request $request): Response {
            if (config('app.debug') === true || $request->expectsJson()) {
                return $response;
            }

            if (! in_array($response->getStatusCode(), [403, 404, 419, 500, 503], strict: true)) {
                return $response;
            }

            // 419 means the CSRF token expired. Bounce back to the form with a
            // message instead of a dead end the student cannot act on.
            if ($response->getStatusCode() === 419) {
                return back()->with('error', 'Your session expired. Please try again.');
            }

            return Inertia::render('Error', ['status' => $response->getStatusCode()])
                ->toResponse($request)
                ->setStatusCode($response->getStatusCode());
        });
    })
    ->create();
