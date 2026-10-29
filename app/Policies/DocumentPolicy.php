<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Documents are private to their owner. Anyone else gets 404 (not 403), so the
 * response is identical to a document that does not exist and IDs cannot be probed.
 */
class DocumentPolicy
{
    public function view(User $user, Document $document): Response
    {
        return $this->ownerOnly($user, $document);
    }

    public function delete(User $user, Document $document): Response
    {
        return $this->ownerOnly($user, $document);
    }

    /**
     * Re-queue processing of a failed document.
     */
    public function retry(User $user, Document $document): Response
    {
        return $this->ownerOnly($user, $document);
    }

    private function ownerOnly(User $user, Document $document): Response
    {
        return $document->isOwnedBy($user) ? Response::allow() : Response::denyAsNotFound();
    }
}
