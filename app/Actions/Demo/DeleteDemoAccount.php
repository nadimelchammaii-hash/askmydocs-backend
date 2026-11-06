<?php

namespace App\Actions\Demo;

use App\Actions\Documents\DeleteDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Deletes a demo account with everything in it: each document through DeleteDocument
 * (database rows, then the PDF file), then the user, whose conversations and
 * messages are removed by ON DELETE CASCADE. Its sessions end with it.
 */
class DeleteDemoAccount
{
    public function __construct(private readonly DeleteDocument $deleteDocument) {}

    public function handle(User $user): void
    {
        if (! $user->isDemo()) {
            throw new InvalidArgumentException('Only demo accounts can be deleted this way.');
        }

        $user->documents()->each(fn ($document) => $this->deleteDocument->handle($document));

        DB::transaction(function () use ($user): void {
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }

            $user->delete();
        });
    }
}
