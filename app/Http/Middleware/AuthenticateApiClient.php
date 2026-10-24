<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\ApiClient;
use App\Services\ApiKeys\ApiKeyService;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica al cliente con "Authorization: Bearer <api key>" y lo asocia a la petición.
 * No se aceptan claves en la URL ni en el cuerpo.
 *
 * Implementa AuthenticatesRequests para que Laravel lo ejecute antes de throttle,
 * ya que el límite de peticiones es por cliente autenticado.
 */
class AuthenticateApiClient implements AuthenticatesRequests
{
    public const string ATTRIBUTE = 'api_client';

    public function __construct(private readonly ApiKeyService $keys) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $key = $token !== null ? $this->keys->authenticate($token) : null;

        if ($key === null) {
            throw ApiException::unauthenticated();
        }

        $client = $key->apiClient;

        if (! $client->isActive()) {
            throw ApiException::clientSuspended();
        }

        $request->attributes->set(self::ATTRIBUTE, $client);
        Context::add('client_id', $client->public_id);
        Context::add('api_key_id', $key->key_id);

        return $next($request);
    }

    public static function client(Request $request): ApiClient
    {
        $client = $request->attributes->get(self::ATTRIBUTE);

        if (! $client instanceof ApiClient) {
            throw ApiException::unauthenticated();
        }

        return $client;
    }
}
