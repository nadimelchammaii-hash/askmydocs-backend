<?php

namespace Database\Seeders;

use App\Actions\Documents\DeleteDocument;
use App\Actions\Documents\StoreDocument;
use App\Models\User;
use App\Services\Pdf\PdfTextExtractor;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Prepares the demo samples: imports every PDF in config('demo.source_path') into
 * the hidden template account through the normal upload path (StoreDocument), so
 * the queue workers extract and embed them like any upload (a one-time OpenAI cost).
 *
 *   php artisan db:seed --class=DemoSeeder --force
 *
 * Safe to run again: PDFs already imported (same content) are skipped, and samples
 * whose PDF was removed from the folder are deleted. Running demos keep their copies.
 */
class DemoSeeder extends Seeder
{
    public function run(PdfTextExtractor $extractor, StoreDocument $storeDocument, DeleteDocument $deleteDocument): void
    {
        $folder = (string) config('demo.source_path');

        if (! is_dir($folder)) {
            $this->command?->warn("No demo source folder at {$folder}: put the sample PDFs there first.");

            return;
        }

        $files = collect(glob(rtrim($folder, '/\\').'/*.pdf') ?: [])->sort()->values();

        $template = User::query()->firstOrCreate(
            ['email' => config('demo.template_email')],
            ['name' => 'Demo samples', 'password' => Str::random(64)],
        );

        $hashes = $files->mapWithKeys(fn (string $path) => [$path => hash_file('sha256', $path)]);

        // Samples whose PDF is no longer in the folder.
        $template->documents()->whereNotIn('sha256', $hashes->values()->all())->get()
            ->each(function ($document) use ($deleteDocument): void {
                $deleteDocument->handle($document);
                $this->command?->info("Removed sample: {$document->title}");
            });

        foreach ($hashes as $path => $hash) {
            if ($template->documents()->where('sha256', $hash)->exists()) {
                $this->command?->line('Already imported: '.basename($path));

                continue;
            }

            $document = $storeDocument->handle(
                user: $template,
                file: new UploadedFile($path, basename($path), 'application/pdf', null, true),
                pageCount: $extractor->inspect($path)->pages,
            );
            $document->update(['is_sample' => true]);

            $this->command?->info("Queued for processing: {$document->title}");
        }

        if ($files->isEmpty()) {
            $this->command?->warn("No PDFs in {$folder}.");
        }
    }
}
