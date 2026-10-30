<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes stored PDFs that no document row refers to any more.
 *
 * Orphans appear when a file deletion fails after its document was deleted
 * (see DeleteDocument) or when the process dies between storing an upload and
 * inserting its row. Recent files are skipped, because during an upload the
 * file briefly exists before its row does.
 *
 * Only files named like the app's own ({user_id}/{ULID}.pdf) are ever deleted;
 * anything else is reported and left alone.
 */
#[Signature('documents:prune-orphans
    {--dry-run : List what would be deleted without deleting anything}
    {--min-age=60 : Only consider files older than this many minutes}')]
#[Description('Delete stored document files that no document refers to')]
class PruneOrphanDocumentFiles extends Command
{
    private const MANAGED_FILE = '/^\d+\/[0-9A-HJKMNP-TV-Z]{26}\.pdf$/i';

    public function handle(): int
    {
        $disk = Storage::disk(config('documents.disk'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subMinutes(max(0, (int) $this->option('min-age')))->getTimestamp();

        $candidates = [];

        foreach ($disk->allFiles() as $path) {
            if (! preg_match(self::MANAGED_FILE, $path)) {
                $this->warn("Skipping unexpected file: {$path}");

                continue;
            }

            if ($disk->lastModified($path) < $cutoff) {
                $candidates[] = $path;
            }
        }

        $orphans = [];

        foreach (array_chunk($candidates, 500) as $chunk) {
            $referenced = Document::whereIn('storage_path', $chunk)->pluck('storage_path')->all();
            array_push($orphans, ...array_diff($chunk, $referenced));
        }

        foreach ($orphans as $path) {
            if (! $dryRun) {
                $disk->delete($path);
            }

            $this->line(($dryRun ? 'Would delete: ' : 'Deleted: ').$path);
        }

        $this->info(sprintf('%d orphaned file(s) %s.', count($orphans), $dryRun ? 'found (dry run, nothing deleted)' : 'deleted'));

        return self::SUCCESS;
    }
}
