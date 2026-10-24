<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Files\DeleteMediaAsset;
use App\Actions\Files\StoreUploadedFile;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiClient;
use App\Http\Requests\Api\V1\StoreFileRequest;
use App\Http\Resources\V1\MediaAssetResource;
use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    public function store(StoreFileRequest $request, StoreUploadedFile $action): JsonResponse
    {
        $asset = $action->handle(AuthenticateApiClient::client($request), $request->file('file'));

        return (new MediaAssetResource($asset))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/files/{$asset->public_id}"));
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        $assets = MediaAsset::query()
            ->ownedBy(AuthenticateApiClient::client($request))
            ->orderByDesc('id')
            ->cursorPaginate($request->integer('limit', 25));

        return MediaAssetResource::collection($assets);
    }

    public function show(Request $request, string $file): MediaAssetResource
    {
        return new MediaAssetResource($this->find($request, $file));
    }

    public function download(Request $request, string $file): StreamedResponse
    {
        $asset = $this->find($request, $file);
        $disk = Storage::disk($asset->disk);

        if (! $disk->exists($asset->path)) {
            throw ApiException::notFound('Archivo');
        }

        $name = pathinfo($asset->original_name ?? $asset->public_id, PATHINFO_FILENAME).'.'.$asset->extension;

        return $disk->download($asset->path, $name, [
            'Content-Type' => $asset->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(Request $request, string $file, DeleteMediaAsset $action): Response
    {
        $action->handle($this->find($request, $file));

        return response()->noContent();
    }

    private function find(Request $request, string $publicId): MediaAsset
    {
        return MediaAsset::query()
            ->ownedBy(AuthenticateApiClient::client($request))
            ->wherePublicId($publicId)
            ->first() ?? throw ApiException::notFound('Archivo');
    }
}
