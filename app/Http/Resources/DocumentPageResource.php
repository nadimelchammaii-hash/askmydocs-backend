<?php

namespace App\Http\Resources;

use App\Models\DocumentPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentPage
 */
class DocumentPageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'document_id' => $this->document_id,
            'page_number' => $this->page_number,
            'text' => $this->text,
            'char_count' => $this->char_count,
        ];
    }
}
