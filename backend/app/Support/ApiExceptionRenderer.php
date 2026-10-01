<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
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
    /**
     * Internal detail requires both the debug flag and a development
     * environment — APP_DEBUG left on in staging must not leak SQL or paths.
     */
    private static function showsDetail(): bool
    {
        return config('app.debug') && app()->environment('local', 'testing');
    }

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

            // A query error carries the SQL and its bindings — customer names and
            // phone numbers — so its message is never rendered, debug or not.
            $e instanceof QueryException => self::renderQueryFailure($e),

            $e instanceof HttpExceptionInterface => ApiResponse::error(
                $e->getMessage() ?: 'Request failed.',
                null,
                $e->getStatusCode(),
            ),

            default => ApiResponse::error(
                self::showsDetail() ? $e->getMessage() : 'An unexpected error occurred.',
                self::showsDetail() ? ['exception' => [class_basename($e)]] : null,
                500,
            ),
        };
    }

    /**
     * A value the column's type cannot hold is a client mistake: the identifier
     * simply does not exist. Anything else is ours, and stays opaque.
     */
    private static function renderQueryFailure(QueryException $e): JsonResponse
    {
        // 22P02 invalid_text_representation, 22023 invalid_parameter_value.
        if (in_array($e->getCode(), ['22P02', '22023'], true)) {
            return ApiResponse::error('Resource not found.', null, 404);
        }

        return ApiResponse::error('An unexpected error occurred.', null, 500);
    }
}
