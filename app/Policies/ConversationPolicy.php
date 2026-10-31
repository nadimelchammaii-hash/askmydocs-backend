<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Conversations are private to their owner; anyone else gets 404 (not 403),
 * exactly like DocumentPolicy, so IDs cannot be probed.
 */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): Response
    {
        return $this->ownerOnly($user, $conversation);
    }

    /** Ask a new question in the conversation. */
    public function ask(User $user, Conversation $conversation): Response
    {
        return $this->ownerOnly($user, $conversation);
    }

    public function update(User $user, Conversation $conversation): Response
    {
        return $this->ownerOnly($user, $conversation);
    }

    public function delete(User $user, Conversation $conversation): Response
    {
        return $this->ownerOnly($user, $conversation);
    }

    private function ownerOnly(User $user, Conversation $conversation): Response
    {
        return $conversation->isOwnedBy($user) ? Response::allow() : Response::denyAsNotFound();
    }
}
