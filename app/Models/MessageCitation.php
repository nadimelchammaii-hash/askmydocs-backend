<?php

namespace App\Models;

use Database\Factories\MessageCitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A page reference supporting an answer. `quote` was verified to appear in the
 * cited chunk; it is document text (untrusted data).
 */
#[Fillable(['message_id', 'document_chunk_id', 'position', 'page_number', 'quote'])]
class MessageCitation extends Model
{
    /** @use HasFactory<MessageCitationFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'page_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return BelongsTo<DocumentChunk, $this>
     */
    public function chunk(): BelongsTo
    {
        return $this->belongsTo(DocumentChunk::class, 'document_chunk_id');
    }
}
