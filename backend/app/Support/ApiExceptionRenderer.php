<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Maps exceptions onto the API envelope. Internal details (SQL, file paths,
 * stack traces) never reach the client outside local debug mode.
 */
class ApiExceptionRenderer
{
    public static function render(Throwable $e, Request $request)
    {
        return match (true) {
            $e instanceof ValidationException => ApiResponse::error(
                'The submitted data is invalid.',
                $e->errors(),
                422,
            ),

            $e instanceof AuthenticationException => ApiResponse::error(
                'Unauthenticated.',
                null,
                401,
            ),

            $e instanceof AuthorizationException => ApiResponse::error(
                $e->getMessage() ?: 'This action is not allowed.',
                null,
                403,
            ),

            // A record belonging to another tenant is simply "not found" — we never
            // confirm that an id exists somewhere else in the system.
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => ApiResponse::error(
                'Resource not found.',
                null,
                404,
            ),

            $e instanceof TooManyRequestsHttpException => ApiResponse::error(
                'Too many requests. Please slow down.',
                null,
                429,
            ),

            $e instanceof HttpExceptionInterface => ApiResponse::error(
                $e->getMessage() ?: 'Request failed.',
                null,
                $e->getStatusCode(),
            ),

            default => ApiResponse::error(
                config('app.debug') ? $e->getMessage() : 'An unexpected error occurred.',
                config('app.debug') ? ['exception' => [class_basename($e)]] : null,
                500,
            ),
        };
    }
}
