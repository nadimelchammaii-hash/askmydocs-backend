<?php

namespace App\Actions\Demo;

use App\Enums\DocumentStatus;
use App\Exceptions\ApiException;
use App\Models\Document;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates a temporary demo account holding copies of the prepared sample documents.
 *
 * The samples belong to a hidden template account (DemoSeeder) and are already
 * processed. Their pages, chunks and embeddings are copied inside the database,
 * so a new demo is ready at once and costs no OpenAI call. Each visitor gets their
 * own copies: nothing they do reaches another visitor or the template.
 */
class StartDemo
{
    public function handle(): User
    {
        $samples = $this->samples();

        if (! config('demo.enabled') || $samples->isEmpty()) {
            throw new ApiException(503, 'demo_unavailable', 'The demo is not available right now. Please try again later.');
        }

        $disk = Storage::disk(config('documents.disk'));
        $copiedFiles = [];

        try {
            return DB::transaction(function () use ($samples, $disk, &$copiedFiles): User {
                $user = new User([
                    'name' => 'Demo visitor',
                    'email' => 'demo-'.Str::lower((string) Str::ulid()).'@'.config('demo.email_domain'),
                    // Never used: demo accounts are signed in by this action only.
                    'password' => Str::random(64),
                ]);
                $user->demo_expires_at = now()->addHours((int) config('demo.lifetime_hours'));
                $user->save();

                foreach ($samples as $sample) {
                    $copiedFiles[] = $this->copySample($sample, $user, $disk);
                }

                return $user;
            });
        } catch (Throwable $exception) {
            // The rows were rolled back; remove the files copied so far.
            foreach ($copiedFiles as $path) {
                $disk->delete($path);
            }

            throw $exception;
        }
    }

    /**
     * @return Collection<int, Document>
     */
    private function samples(): Collection
    {
        $template = User::query()->where('email', config('demo.template_email'))->first();

        return $template === null
            ? collect()
            : $template->documents()->where('status', DocumentStatus::Ready)->oldest()->oldest('id')->get();
    }

    /**
     * Copies one sample to the demo account; returns the path of the copied file.
     */
    private function copySample(Document $sample, User $user, Filesystem $disk): string
    {
        $copy = $sample->replicate();
        $copy->id = $copy->newUniqueId();
        $copy->user_id = $user->id;
        $copy->is_sample = true;
        // Same naming as uploads (StoreDocument): user ID and document ULID only.
        $copy->storage_path = "{$user->id}/{$copy->id}.pdf";

        $disk->copy($sample->storage_path, $copy->storage_path);
        $copy->save();

        DB::statement(
            'insert into document_pages (document_id, page_number, text, char_count)
             select ?, page_number, text, char_count from document_pages where document_id = ?',
            [$copy->id, $sample->id],
        );

        DB::statement(
            'insert into document_chunks (document_id, chunk_index, page_number, content, char_count, embedding)
             select ?, chunk_index, page_number, content, char_count, embedding from document_chunks where document_id = ?',
            [$copy->id, $sample->id],
        );

        return $copy->storage_path;
    }
}
