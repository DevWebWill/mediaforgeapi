<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Enums\WebhookDeliveryStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiClient;
use App\Http\Requests\Api\V1\WebhookEndpointRequest;
use App\Http\Resources\V1\WebhookDeliveryResource;
use App\Http\Resources\V1\WebhookEndpointResource;
use App\Jobs\DeliverWebhook;
use App\Models\ApiClient;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\Exceptions\UnsafeWebhookUrlException;
use App\Services\Webhooks\WebhookEmitter;
use App\Services\Webhooks\WebhookSigner;
use App\Services\Webhooks\WebhookUrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WebhookController extends Controller
{
    public function __construct(private readonly WebhookUrlGuard $guard) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $endpoints = WebhookEndpoint::query()
            ->ownedBy(AuthenticateApiClient::client($request))
            ->orderBy('id')
            ->get();

        return WebhookEndpointResource::collection($endpoints);
    }

    public function store(WebhookEndpointRequest $request): JsonResponse
    {
        $client = AuthenticateApiClient::client($request);
        $data = $request->validated();
        $this->assertSafe($data['url']);
        $secret = WebhookSigner::generateSecret();

        $endpoint = DB::transaction(function () use ($client, $data, $secret): WebhookEndpoint {
            ApiClient::query()->whereKey($client->id)->lockForUpdate()->first();

            $max = (int) config('mediaforge.webhooks.max_endpoints_per_client');
            if (WebhookEndpoint::query()->ownedBy($client)->count() >= $max) {
                throw new ApiException(ApiErrorCode::WebhookLimitExceeded, "Se ha alcanzado el máximo de {$max} webhooks.", 422, [
                    'max_webhooks' => $max,
                ]);
            }

            $endpoint = new WebhookEndpoint([
                'url' => $data['url'],
                'description' => $data['description'] ?? null,
                'events' => array_values($data['events']),
                'is_active' => $data['is_active'] ?? true,
                'secret' => $secret,
            ]);
            $endpoint->api_client_id = $client->id;
            $endpoint->save();

            return $endpoint;
        });

        return (new WebhookEndpointResource($endpoint))->withSecret($secret)->response()->setStatusCode(201);
    }

    public function show(Request $request, string $webhook): WebhookEndpointResource
    {
        return new WebhookEndpointResource($this->find($request, $webhook));
    }

    public function update(WebhookEndpointRequest $request, string $webhook): WebhookEndpointResource
    {
        $endpoint = $this->find($request, $webhook);
        $data = $request->validated();

        if (isset($data['url'])) {
            $this->assertSafe($data['url']);
        }

        if (isset($data['events'])) {
            $data['events'] = array_values($data['events']);
        }

        $endpoint->fill($data)->save();

        return new WebhookEndpointResource($endpoint);
    }

    public function destroy(Request $request, string $webhook): Response
    {
        $endpoint = $this->find($request, $webhook);
        $endpoint->forceFill(['is_active' => false])->save();
        $endpoint->delete();

        return response()->noContent();
    }

    /** Genera un secreto nuevo; el anterior sigue firmando durante el periodo de gracia. */
    public function rotateSecret(Request $request, string $webhook): WebhookEndpointResource
    {
        $endpoint = $this->find($request, $webhook);
        $data = $request->validate([
            'grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:10080'],
        ]);
        $grace = (int) ($data['grace_minutes'] ?? config('mediaforge.webhooks.rotation_grace_minutes'));
        $secret = WebhookSigner::generateSecret();

        $endpoint->forceFill([
            'previous_secret' => $grace > 0 ? $endpoint->secret : null,
            'previous_secret_expires_at' => $grace > 0 ? now()->addMinutes($grace) : null,
            'secret' => $secret,
        ])->save();

        return (new WebhookEndpointResource($endpoint))->withSecret($secret);
    }

    public function ping(Request $request, string $webhook, WebhookEmitter $emitter): JsonResponse
    {
        $endpoint = $this->find($request, $webhook);

        if (! $endpoint->is_active) {
            throw ValidationException::withMessages(['webhook' => 'El webhook está desactivado.']);
        }

        $delivery = $emitter->ping($endpoint);

        return (new WebhookDeliveryResource($delivery->refresh()->load('event')))->response()->setStatusCode(202);
    }

    public function deliveries(Request $request, string $webhook): AnonymousResourceCollection
    {
        $endpoint = $this->find($request, $webhook);
        $filters = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(array_column(WebhookDeliveryStatus::cases(), 'value'))],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $deliveries = $endpoint->deliveries()
            ->with('event')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')
            ->cursorPaginate($request->integer('limit', 25));

        return WebhookDeliveryResource::collection($deliveries);
    }

    /** Reenvía manualmente una entrega finalizada (útil tras corregir el receptor). */
    public function redeliver(Request $request, string $webhook, string $delivery): JsonResponse
    {
        $endpoint = $this->find($request, $webhook);

        if (! $endpoint->is_active) {
            throw ValidationException::withMessages(['webhook' => 'El webhook está desactivado.']);
        }

        $model = $endpoint->deliveries()->wherePublicId($delivery)->first() ?? throw ApiException::notFound('Entrega');

        $reset = WebhookDelivery::query()
            ->whereKey($model->id)
            ->where('status', '!=', WebhookDeliveryStatus::Pending->value)
            ->update([
                'status' => WebhookDeliveryStatus::Pending->value,
                'attempts' => 0,
                'last_error' => null,
                'next_attempt_at' => now(),
                'delivered_at' => null,
                'updated_at' => now(),
            ]);

        if ($reset === 1) {
            DeliverWebhook::dispatch($model->id)->onQueue((string) config('mediaforge.webhooks.queue'));
        }

        return (new WebhookDeliveryResource($model->refresh()->load('event')))->response()->setStatusCode(202);
    }

    private function assertSafe(string $url): void
    {
        try {
            $this->guard->inspect($url);
        } catch (UnsafeWebhookUrlException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }
    }

    private function find(Request $request, string $publicId): WebhookEndpoint
    {
        return WebhookEndpoint::query()
            ->ownedBy(AuthenticateApiClient::client($request))
            ->wherePublicId($publicId)
            ->first() ?? throw ApiException::notFound('Webhook');
    }
}
