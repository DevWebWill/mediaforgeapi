<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Media\Operations\MediaOperation;
use App\Media\Operations\OperationRegistry;
use App\Media\Profiles\Profile;
use App\Media\Profiles\ProfileRegistry;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function __construct(
        private readonly OperationRegistry $operations,
        private readonly ProfileRegistry $profiles,
    ) {}

    public function operations(): JsonResponse
    {
        return response()->json([
            'data' => array_values(array_map(fn (MediaOperation $o): array => $o->describe(), $this->operations->all())),
        ]);
    }

    public function operation(string $operation): JsonResponse
    {
        if (! $this->operations->has($operation)) {
            throw ApiException::notFound('Operación');
        }

        return response()->json(['data' => $this->operations->get($operation)->describe()]);
    }

    public function profiles(): JsonResponse
    {
        return response()->json([
            'data' => array_values(array_map(fn (Profile $p): array => $this->describeProfile($p), $this->profiles->all())),
        ]);
    }

    public function profile(string $profile): JsonResponse
    {
        if (! $this->profiles->has($profile)) {
            throw ApiException::notFound('Perfil');
        }

        return response()->json(['data' => $this->describeProfile($this->profiles->get($profile))]);
    }

    /** @return array<string, mixed> */
    private function describeProfile(Profile $profile): array
    {
        return [
            'object' => 'profile',
            ...$profile->toArray(),
            'notice' => 'Parámetros de partida. La compatibilidad final depende de las especificaciones vigentes de cada plataforma.',
        ];
    }
}
