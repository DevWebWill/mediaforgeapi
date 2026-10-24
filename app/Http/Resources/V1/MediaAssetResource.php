<?php

namespace App\Http\Resources\V1;

use App\Models\MediaAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MediaAsset */
class MediaAssetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'object' => 'file',
            'kind' => $this->kind->value,
            'original_name' => $this->original_name,
            'extension' => $this->extension,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'checksum_sha256' => $this->checksum_sha256,
            'metadata' => $this->metadata,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'links' => [
                'self' => url("/api/v1/files/{$this->public_id}"),
                'download' => url("/api/v1/files/{$this->public_id}/download"),
            ],
        ];
    }
}
