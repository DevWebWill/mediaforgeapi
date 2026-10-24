<?php

namespace App\Actions\Jobs;

use App\Enums\ApiErrorCode;
use App\Enums\AssetKind;
use App\Enums\JobPriority;
use App\Enums\JobStatus;
use App\Exceptions\ApiException;
use App\Media\Operations\OperationRegistry;
use App\Media\Profiles\ProfileRegistry;
use App\Models\ApiClient;
use App\Models\MediaAsset;
use App\Models\MediaJob;
use App\Models\WebhookEndpoint;
use App\Services\Jobs\JobDispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Caso de uso: aceptar un trabajo de procesamiento.
 *
 * 1. Valida operación, perfil, parámetros, archivo y prioridad.
 * 2. Idempotencia: misma clave + misma petición => devuelve el trabajo existente;
 *    misma clave + petición distinta => 409. Ámbito: cliente. Caducidad configurable.
 * 3. Persiste el trabajo (pending) en una transacción corta que respeta el límite
 *    de trabajos activos del cliente.
 * 4. Tras el commit lo encola y lo marca como queued. Si Redis falla, el trabajo
 *    queda pending en MySQL y puede reencolarse sin perderse.
 */
final class CreateMediaJob
{
    public function __construct(
        private readonly OperationRegistry $operations,
        private readonly ProfileRegistry $profiles,
        private readonly JobDispatcher $dispatcher,
    ) {}

    /** @param  array<string, mixed>  $input  datos ya validados estructuralmente por StoreJobRequest */
    public function handle(ApiClient $client, array $input, ?string $idempotencyKey): CreatedMediaJob
    {
        $operation = $this->operations->get($input['operation']);
        $profile = isset($input['profile']) ? $this->profiles->get($input['profile']) : null;

        if ($profile !== null && ! $profile->supports($operation->name())) {
            throw ValidationException::withMessages([
                'profile' => "El perfil {$profile->id} no admite la operación {$operation->name()}.",
            ]);
        }

        $parameters = $operation->resolveParameters([
            ...($profile?->parametersFor($operation) ?? []),
            ...($input['parameters'] ?? []),
        ]);

        $priority = JobPriority::from($input['priority'] ?? JobPriority::Normal->value);
        if ($priority->exceeds($client->maxPriority())) {
            throw ValidationException::withMessages([
                'priority' => "La prioridad máxima permitida para este cliente es {$client->maxPriority()->value}.",
            ]);
        }

        $asset = MediaAsset::query()->ownedBy($client)
            ->wherePublicId($input['file_id'])
            ->where('kind', AssetKind::Original)
            ->first();

        if ($asset === null) {
            throw ValidationException::withMessages(['file_id' => 'El archivo indicado no existe.']);
        }

        $webhook = null;
        if (isset($input['webhook_url'])) {
            $webhook = WebhookEndpoint::query()->ownedBy($client)
                ->where('is_active', true)
                ->get()
                ->first(fn (WebhookEndpoint $endpoint) => $endpoint->url === $input['webhook_url']);

            if ($webhook === null) {
                throw ValidationException::withMessages([
                    'webhook_url' => 'webhook_url debe coincidir con un webhook activo registrado en POST /api/v1/webhooks.',
                ]);
            }
        }

        $attributes = [
            'input_asset_id' => $asset->id,
            'webhook_endpoint_id' => $webhook?->id,
            'operation' => $operation->name(),
            'profile' => $profile?->id,
            'profile_version' => $profile?->version,
            'parameters' => $parameters,
            'priority' => $priority,
            'queue' => $priority->queue(),
            'reference' => $input['reference'] ?? null,
            'scheduled_at' => isset($input['scheduled_at']) ? Carbon::parse($input['scheduled_at']) : null,
            'processing_deadline' => isset($input['processing_deadline']) ? Carbon::parse($input['processing_deadline']) : null,
        ];

        $fingerprint = $idempotencyKey !== null ? $this->fingerprint($input) : null;

        if ($idempotencyKey !== null && ($existing = $this->findReplay($client, $idempotencyKey, $fingerprint)) !== null) {
            return new CreatedMediaJob($existing, replayed: true);
        }

        try {
            $job = $this->persist($client, $attributes, $idempotencyKey, $fingerprint);
        } catch (UniqueConstraintViolationException $e) {
            // Dos peticiones simultáneas con la misma clave: gana la primera
            if ($idempotencyKey !== null && ($existing = $this->findReplay($client, $idempotencyKey, $fingerprint)) !== null) {
                return new CreatedMediaJob($existing, replayed: true);
            }

            throw $e;
        }

        $this->dispatcher->dispatch($job);

        return new CreatedMediaJob($job, replayed: false);
    }

    /** @param  array<string, mixed>  $attributes */
    private function persist(ApiClient $client, array $attributes, ?string $idempotencyKey, ?string $fingerprint): MediaJob
    {
        return DB::transaction(function () use ($client, $attributes, $idempotencyKey, $fingerprint): MediaJob {
            // Serializa la creación por cliente para que el límite de activos sea exacto
            ApiClient::query()->whereKey($client->id)->lockForUpdate()->first();

            $active = MediaJob::query()->ownedBy($client)->active()->count();
            if ($active >= $client->maxActiveJobs()) {
                throw new ApiException(ApiErrorCode::ActiveJobsLimitExceeded, 'Se ha alcanzado el número máximo de trabajos activos.', 429, [
                    'max_active_jobs' => $client->maxActiveJobs(),
                ]);
            }

            /** @var MediaJob $job */
            $job = $client->mediaJobs()->create([
                ...$attributes,
                'status' => JobStatus::Pending,
                'max_attempts' => (int) config('mediaforge.jobs.max_attempts'),
                'request_id' => Context::get('request_id'),
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'idempotency_expires_at' => $idempotencyKey !== null
                    ? now()->addHours((int) config('mediaforge.jobs.idempotency_ttl_hours'))
                    : null,
            ]);

            $job->events()->create(['type' => 'created', 'to_status' => JobStatus::Pending]);

            return $job;
        });
    }

    private function findReplay(ApiClient $client, string $key, string $fingerprint): ?MediaJob
    {
        $existing = MediaJob::query()->ownedBy($client)->where('idempotency_key', $key)->first();

        if ($existing === null) {
            return null;
        }

        if ($existing->idempotency_expires_at !== null && $existing->idempotency_expires_at->isPast()) {
            // Clave caducada: se libera para que pueda reutilizarse
            MediaJob::query()->whereKey($existing->id)->update([
                'idempotency_key' => null,
                'request_fingerprint' => null,
                'idempotency_expires_at' => null,
            ]);

            return null;
        }

        if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
            throw new ApiException(
                ApiErrorCode::IdempotencyKeyReused,
                'La clave de idempotencia ya se usó con una petición diferente.',
                409,
            );
        }

        return $existing;
    }

    /** @param  array<string, mixed>  $input */
    private function fingerprint(array $input): string
    {
        return hash('sha256', json_encode($this->canonicalize($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->canonicalize(...), $value);
    }
}
