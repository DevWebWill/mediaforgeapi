<?php

namespace App\Exceptions;

use App\Enums\ApiErrorCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Traduce cualquier excepción al contrato de error público:
 * { "error": { "code", "message", "details"?, "request_id" } }
 *
 * Nunca incluye trazas, clases internas ni mensajes de FFmpeg.
 */
final class ApiErrorRenderer
{
    public static function render(Throwable $e): JsonResponse
    {
        [$code, $message, $status, $details, $headers] = match (true) {
            $e instanceof ApiException => [$e->errorCode, $e->getMessage(), $e->status, $e->details, $e->headers],
            $e instanceof ValidationException => [ApiErrorCode::ValidationFailed, 'Los datos enviados no son válidos.', 422, $e->errors(), []],
            $e instanceof AuthenticationException => [ApiErrorCode::Unauthenticated, 'Credenciales de API ausentes o no válidas.', 401, null, ['WWW-Authenticate' => 'Bearer']],
            $e instanceof AuthorizationException => [ApiErrorCode::Forbidden, 'No tienes permiso para realizar esta acción.', 403, null, []],
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [ApiErrorCode::NotFound, 'Recurso no encontrado.', 404, null, []],
            $e instanceof MethodNotAllowedHttpException => [ApiErrorCode::MethodNotAllowed, 'Método HTTP no permitido para este recurso.', 405, null, $e->getHeaders()],
            $e instanceof ThrottleRequestsException => [ApiErrorCode::RateLimited, 'Demasiadas peticiones. Reintenta más tarde.', 429, null, $e->getHeaders()],
            $e instanceof PostTooLargeException => [ApiErrorCode::FileTooLarge, 'La petición supera el tamaño máximo permitido.', 413, null, []],
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 503 => [ApiErrorCode::ServiceUnavailable, 'Servicio no disponible temporalmente.', 503, null, $e->getHeaders()],
            $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 => [ApiErrorCode::HttpError, 'La petición no se puede procesar.', $e->getStatusCode(), null, $e->getHeaders()],
            default => [ApiErrorCode::InternalError, 'Error interno del servidor.', 500, null, []],
        };

        $error = ['code' => $code->value, 'message' => $message];

        if ($details !== null) {
            $error['details'] = $details;
        }

        $error['request_id'] = Context::get('request_id');

        return new JsonResponse(['error' => $error], $status, $headers);
    }
}
