<?php

namespace App\Exceptions;

use App\Enums\ApiErrorCode;
use RuntimeException;

/**
 * Error de negocio con representación estable en el contrato HTTP.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $details
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly ApiErrorCode $errorCode,
        string $message,
        public readonly int $status,
        public readonly ?array $details = null,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function unauthenticated(): self
    {
        return new self(ApiErrorCode::Unauthenticated, 'Credenciales de API ausentes o no válidas.', 401, headers: ['WWW-Authenticate' => 'Bearer']);
    }

    public static function clientSuspended(): self
    {
        return new self(ApiErrorCode::ClientSuspended, 'El cliente API está suspendido.', 403);
    }

    public static function notFound(string $resource = 'Recurso'): self
    {
        return new self(ApiErrorCode::NotFound, "{$resource} no encontrado.", 404);
    }
}
