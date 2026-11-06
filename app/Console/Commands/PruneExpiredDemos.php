<?php

namespace App\Console\Commands;

use App\Actions\Demo\DeleteDemoAccount;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('demo:prune')]
#[Description('Delete demo accounts (and their documents and files) whose time is up')]
class PruneExpiredDemos extends Command
{
    public function handle(DeleteDemoAccount $deleteDemoAccount): int
    {
        $deleted = 0;

        User::query()
            ->whereNotNull('demo_expires_at')
            ->where('demo_expires_at', '<=', now())
            ->lazyById()
            ->each(function (User $user) use ($deleteDemoAccount, &$deleted): void {
                try {
                    $deleteDemoAccount->handle($user);
                    $deleted++;
                } catch (Throwable $exception) {
                    // Retried on the next run; one failure must not stop the others.
                    Log::warning('Expired demo account could not be deleted', [
                        'user_id' => $user->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });

        $this->info("Deleted {$deleted} expired demo ".str('account')->plural($deleted).'.');

        return self::SUCCESS;
    }
}
