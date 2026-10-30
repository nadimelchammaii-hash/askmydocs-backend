<?php

namespace App\Actions\Documents;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Saves a validated PDF upload: first the file, then the database row.
 *
 * The file is named after the document's ULID (never the client's filename), so
 * user input never reaches a filesystem path. If the row cannot be created, the
 * file is removed again so no orphan is left behind.
 */
class StoreDocument
{
    public function handle(User $user, UploadedFile $file, int $pageCount, ?string $title = null): Document
    {
        $id = (string) Str::ulid();
        $disk = Storage::disk(config('documents.disk'));
        $path = $disk->putFileAs((string) $user->id, $file, "{$id}.pdf");
        $originalFilename = $this->cleanFilename($file->getClientOriginalName());

        try {
            $document = new Document([
                'user_id' => $user->id,
                'title' => $this->title($title, $originalFilename),
                'original_filename' => $originalFilename,
                'storage_path' => $path,
                'size_bytes' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'page_count' => $pageCount,
                'status' => DocumentStatus::Queued,
            ]);
            $document->id = $id;
            $document->save();
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }

        return $document;
    }

    /**
     * The client's filename is untrusted display text: keep only the last path
     * segment, drop control characters and cap the length.
     */
    private function cleanFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim(preg_replace('/\p{Cc}+/u', '', mb_scrub($name, 'UTF-8')) ?? '');

        return Str::limit($name, 255, '') ?: 'document.pdf';
    }

    private function title(?string $title, string $filename): string
    {
        $title = trim((string) $title);

        if ($title === '') {
            $title = trim(preg_replace('/\.pdf$/i', '', $filename) ?? '');
        }

        return $title === '' ? 'Untitled document' : $title;
    }
}
