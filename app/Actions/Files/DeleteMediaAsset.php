<?php

namespace App\Actions\Files;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\MediaAsset;
use App\Models\MediaJob;
use Illuminate\Support\Facades\Storage;

final class DeleteMediaAsset
{
    public function handle(MediaAsset $asset): void
    {
        $inUse = MediaJob::query()->active()
            ->where(fn ($q) => $q->where('input_asset_id', $asset->id)->orWhere('id', $asset->media_job_id))
            ->exists();

        if ($inUse) {
            throw new ApiException(ApiErrorCode::FileInUse, 'El archivo está siendo utilizado por un trabajo activo.', 409);
        }

        // Se conserva el registro (soft delete) para la trazabilidad de los trabajos históricos
        $asset->delete();
        Storage::disk($asset->disk)->delete($asset->path);
    }
}
