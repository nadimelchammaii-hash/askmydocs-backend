<?php

namespace App\Models;

use Database\Factories\DocumentChunkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A chunk of one page's text and its embedding. The content comes from an
 * uploaded PDF: it is untrusted data, never instructions.
 */
#[Fillable(['document_id', 'chunk_index', 'page_number', 'content', 'char_count', 'embedding'])]
#[Hidden(['embedding'])] // 1,536 numbers: never serialized by accident.
class DocumentChunk extends Model
{
    /** @use HasFactory<DocumentChunkFactory> */
    use HasFactory;

    /** Chunks are derived data, rewritten as a whole on re-embedding. */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'page_number' => 'integer',
            'char_count' => 'integer',
            'embedding' => AsVector::class,
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
