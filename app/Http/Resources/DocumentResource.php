<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    /**
     * Internal fields (storage_path, sha256, user_id) are never exposed.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'original_filename' => $this->original_filename,
            'size_bytes' => $this->size_bytes,
            'page_count' => $this->page_count,
            'status' => $this->status->value,
            'is_finished' => $this->status->isFinished(),
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'conversations_count' => $this->whenCounted('conversations'),
        ];
    }
}
