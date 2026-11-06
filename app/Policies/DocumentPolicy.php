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

    /** Rename the document. */
    public function update(User $user, Document $document): Response
    {
        return $this->ownerAndNotSample($user, $document);
    }

    public function delete(User $user, Document $document): Response
    {
        return $this->ownerAndNotSample($user, $document);
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

    /**
     * Demo samples stay as they are: their owner may view them and ask questions,
     * but not rename or delete them. Other users still get 404.
     */
    private function ownerAndNotSample(User $user, Document $document): Response
    {
        $owner = $this->ownerOnly($user, $document);

        if ($owner->denied() || ! $document->is_sample) {
            return $owner;
        }

        return Response::deny('Sample documents in the demo cannot be renamed or deleted.');
    }
}
