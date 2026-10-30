<?php

namespace App\Actions\Documents;

use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Permanently deletes a document: the database rows first, the file second.
 *
 * Why this order: if the transaction fails, nothing changed and the file is
 * still there. If the file deletion fails afterwards, only an unreferenced
 * file is left, which the orphan cleanup command removes later. The reverse
 * order could leave a document row pointing at a missing file.
 *
 * Pages (and, in later phases, chunks, conversations, messages and citations)
 * are removed by ON DELETE CASCADE foreign keys inside the same transaction.
 */
class DeleteDocument
{
    public function handle(Document $document): void
    {
        $path = $document->storage_path;

        DB::transaction(fn () => $document->delete());

        try {
            Storage::disk(config('documents.disk'))->delete($path);
        } catch (Throwable $exception) {
            Log::warning('Document file could not be deleted; left for orphan cleanup', [
                'document_id' => $document->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
