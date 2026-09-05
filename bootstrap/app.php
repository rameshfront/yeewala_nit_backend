<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(\App\Http\Middleware\ForceCors::class);
        $middleware->statefulApi();
        $middleware->validateCsrfTokens(except: [
            'api/*',
            'sanctum/csrf-cookie',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // 1. Validation Errors (422 Unprocessable Content)
        $exceptions->render(function (ValidationException $e, $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                $errorItems = [];
                foreach ($e->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $errorItems[] = [
                            'code' => 'VALIDATION_ERROR',
                            'field' => $field,
                            'message' => $message,
                        ];
                    }
                }
                return response()->json([
                    'data' => null,
                    'meta' => null,
                    'errors' => $errorItems,
                ], 422);
            }
        });

        // 2. Unauthenticated Errors (401 Unauthorized)
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'data' => null,
                    'meta' => null,
                    'errors' => [
                        [
                            'code' => 'UNAUTHENTICATED',
                            'message' => 'You must be logged in to perform this action.',
                        ]
                    ],
                ], 401);
            }
        });

        // 3. Not Found Errors (404 Not Found)
        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'data' => null,
                    'meta' => null,
                    'errors' => [
                        [
                            'code' => 'NOT_FOUND',
                            'message' => 'The requested resource or endpoint could not be found.',
                        ]
                    ],
                ], 404);
            }
        });

        // 4. Forbidden Errors (403 Forbidden)
        $exceptions->render(function (AccessDeniedHttpException $e, $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'data' => null,
                    'meta' => null,
                    'errors' => [
                        [
                            'code' => 'FORBIDDEN',
                            'message' => 'You do not have permission to access this resource.',
                        ]
                    ],
                ], 403);
            }
        });

        // 5. Rate Limit / High Traffic Errors (429 Too Many Requests)
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException $e, $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'data' => null,
                    'meta' => null,
                    'errors' => [
                        [
                            'code' => 'TOO_MANY_REQUESTS',
                            'message' => 'Our servers are experiencing high traffic right now. Please try again in a few moments.',
                        ]
                    ],
                ], 429);
            }
        });

        // 5. Generic Server Errors (500 Internal Server Error)
        $exceptions->render(function (Throwable $e, $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                $status = ($e instanceof HttpExceptionInterface) ? $e->getStatusCode() : 500;
                $message = config('app.debug') ? $e->getMessage() : 'An internal server error occurred.';
                
                return response()->json([
                    'data' => null,
                    'meta' => null,
                    'errors' => [
                        [
                            'code' => 'SERVER_ERROR',
                            'message' => $message,
                        ]
                    ],
                ], $status);
            }
        });
    })->create();
