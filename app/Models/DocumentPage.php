<?php

namespace App\Models;

use Database\Factories\DocumentPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['document_id', 'page_number', 'text', 'char_count'])]
class DocumentPage extends Model
{
    /** @use HasFactory<DocumentPageFactory> */
    use HasFactory;

    /** Pages are derived data, rewritten as a whole on re-extraction. */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page_number' => 'integer',
            'char_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
