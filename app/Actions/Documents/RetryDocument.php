<?php

namespace App\Actions\Documents;

use App\Enums\DocumentStatus;
use App\Jobs\ExtractDocumentText;
use App\Models\Document;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Re-queues processing of a failed document.
 */
class RetryDocument
{
    /**
     * @throws ConflictHttpException when the document is not in the failed state
     */
    public function handle(Document $document): Document
    {
        // One conditional UPDATE: of two simultaneous retry requests only one
        // matches, so the job is queued once.
        $claimed = Document::whereKey($document->id)
            ->where('status', DocumentStatus::Failed)
            ->update([
                'status' => DocumentStatus::Queued,
                'error_code' => null,
                'error_message' => null,
                'processing_started_at' => null,
                'processed_at' => null,
            ]);

        if ($claimed === 0) {
            throw new ConflictHttpException('Only documents whose processing failed can be retried.');
        }

        $document->refresh();
        ExtractDocumentText::dispatch($document);

        return $document;
    }
}
