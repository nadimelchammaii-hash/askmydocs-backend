<?php

namespace App\Http\Controllers;

use App\Actions\Documents\DeleteDocument;
use App\Actions\Documents\RetryDocument;
use App\Actions\Documents\StoreDocument;
use App\Http\Requests\Documents\ListDocumentsRequest;
use App\Http\Requests\Documents\RenameDocumentRequest;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Http\Resources\DocumentPageResource;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ownership is enforced by the routes (`can:view,document` / `can:delete,document`),
 * which answer 404 for other users' documents. See DocumentPolicy.
 */
class DocumentController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * The user's documents, newest first; optionally by status and title search.
     */
    public function index(ListDocumentsRequest $request): AnonymousResourceCollection
    {
        $documents = $request->user()->documents()
            ->when($request->validated('status'), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->searchPattern(), fn ($query, string $pattern) => $query->where('title', 'ilike', $pattern))
            ->withCount('conversations')
            ->latest()
            ->latest('id') // ULIDs are time-ordered: a stable tie-breaker for equal timestamps.
            ->paginate(self::PER_PAGE);

        return DocumentResource::collection($documents);
    }

    public function store(StoreDocumentRequest $request, StoreDocument $storeDocument): JsonResponse
    {
        $document = $storeDocument->handle(
            user: $request->user(),
            file: $request->file('file'),
            pageCount: $request->pageCount(),
            title: $request->validated('title'),
        );

        // 202 Accepted: stored, but text extraction has not happened yet.
        // The client polls GET /documents/{id} until `is_finished` is true.
        return (new DocumentResource($document))->response()->setStatusCode(202);
    }

    public function show(Document $document): DocumentResource
    {
        return new DocumentResource($document);
    }

    /**
     * Streams the original PDF to its owner (shown inline by the browser).
     */
    public function file(Document $document): StreamedResponse
    {
        $disk = Storage::disk(config('documents.disk'));

        abort_unless($disk->exists($document->storage_path), 404);

        return $disk->response($document->storage_path, $document->original_filename, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    public function page(Document $document, int $pageNumber): DocumentPageResource
    {
        return new DocumentPageResource(
            $document->pages()->where('page_number', $pageNumber)->firstOrFail(),
        );
    }

    public function retry(Document $document, RetryDocument $retryDocument): JsonResponse
    {
        return (new DocumentResource($retryDocument->handle($document)))->response()->setStatusCode(202);
    }

    public function update(RenameDocumentRequest $request, Document $document): DocumentResource
    {
        $document->update(['title' => $request->validated('title')]);

        return new DocumentResource($document);
    }

    public function destroy(Document $document, DeleteDocument $deleteDocument): Response
    {
        $deleteDocument->handle($document);

        return response()->noContent();
    }
}
