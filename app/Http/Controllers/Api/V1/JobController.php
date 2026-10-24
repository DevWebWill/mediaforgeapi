<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Jobs\CancelMediaJob;
use App\Actions\Jobs\CreateMediaJob;
use App\Enums\ApiErrorCode;
use App\Enums\JobStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiClient;
use App\Http\Requests\Api\V1\StoreJobRequest;
use App\Http\Resources\V1\MediaAssetResource;
use App\Http\Resources\V1\MediaJobEventResource;
use App\Http\Resources\V1\MediaJobResource;
use App\Models\MediaJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class JobController extends Controller
{
    public function store(StoreJobRequest $request, CreateMediaJob $action): JsonResponse
    {
        $created = $action->handle(
            AuthenticateApiClient::client($request),
            $request->validated(),
            $request->idempotencyKey(),
        );

        $job = $created->job->load('inputAsset');
        $location = url("/api/v1/jobs/{$job->public_id}");

        $response = (new MediaJobResource($job))->response()->setStatusCode(202)->header('Location', $location);

        if ($created->replayed) {
            $response->header('Idempotent-Replayed', 'true');
        }

        return $response;
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(JobStatus::values())],
            'operation' => ['sometimes', 'string', 'max:64'],
            'reference' => ['sometimes', 'string', 'max:191'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $jobs = MediaJob::query()
            ->ownedBy(AuthenticateApiClient::client($request))
            ->with('inputAsset')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['operation'] ?? null, fn ($q, $v) => $q->where('operation', $v))
            ->when($filters['reference'] ?? null, fn ($q, $v) => $q->where('reference', $v))
            ->orderByDesc('id')
            ->cursorPaginate($request->integer('limit', 25));

        return MediaJobResource::collection($jobs);
    }

    public function show(Request $request, string $job): MediaJobResource
    {
        return new MediaJobResource($this->find($request, $job));
    }

    public function cancel(Request $request, string $job, CancelMediaJob $action): JsonResponse
    {
        $model = $this->find($request, $job);
        $cancelled = $action->handle($model);

        // 200: cancelado. 202: cancelación cooperativa solicitada a un trabajo en ejecución
        return (new MediaJobResource($model->refresh()->load('inputAsset')))
            ->response()
            ->setStatusCode($cancelled ? 200 : 202);
    }

    public function result(Request $request, string $job): JsonResponse
    {
        $model = $this->find($request, $job);

        if ($model->status !== JobStatus::Completed) {
            throw new ApiException(ApiErrorCode::JobNotCompleted, 'El trabajo todavía no ha finalizado correctamente.', 409, [
                'status' => $model->status->value,
            ]);
        }

        $outputs = $model->outputs()->orderBy('id')->get();

        return response()->json([
            'data' => [
                'object' => 'job_result',
                'job_id' => $model->public_id,
                'operation' => $model->operation,
                'result' => $model->result,
                'outputs' => MediaAssetResource::collection($outputs),
            ],
        ]);
    }

    public function logs(Request $request, string $job): AnonymousResourceCollection
    {
        $model = $this->find($request, $job);

        return MediaJobEventResource::collection($model->events()->orderBy('id')->limit(500)->get());
    }

    private function find(Request $request, string $publicId): MediaJob
    {
        return MediaJob::query()
            ->ownedBy(AuthenticateApiClient::client($request))
            ->with('inputAsset')
            ->wherePublicId($publicId)
            ->first() ?? throw ApiException::notFound('Trabajo');
    }
}
