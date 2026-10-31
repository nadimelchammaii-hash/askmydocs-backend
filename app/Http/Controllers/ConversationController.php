<?php

namespace App\Http\Controllers;

use App\Actions\Conversations\AskQuestion;
use App\Http\Requests\Conversations\AskQuestionRequest;
use App\Http\Requests\Conversations\ListConversationsRequest;
use App\Http\Requests\Conversations\RenameConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Conversations and questions. Answers are generated in the background: asking
 * returns 202 with a pending answer, which the client polls until is_finished.
 * Ownership is enforced by the routes (404 for other users' resources).
 */
class ConversationController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * The user's conversations, most recently active first.
     */
    public function index(ListConversationsRequest $request): AnonymousResourceCollection
    {
        $conversations = $request->user()->conversations()
            ->when($request->validated('document_id'), fn ($query, string $id) => $query->where('document_id', $id))
            ->with('document:id,title')
            ->withCount('messages')
            ->latest('updated_at')
            ->latest('id')
            ->paginate(self::PER_PAGE);

        return ConversationResource::collection($conversations);
    }

    /**
     * A whole conversation with every message and its citations (oldest first).
     */
    public function show(Conversation $conversation): ConversationResource
    {
        return new ConversationResource($conversation->load(['document:id,title', 'messages.citations']));
    }

    public function store(AskQuestionRequest $request, Document $document, AskQuestion $ask): JsonResponse
    {
        $conversation = $ask->start($request->user(), $document, $request->question());

        return (new ConversationResource($conversation))->response()->setStatusCode(202);
    }

    public function ask(AskQuestionRequest $request, Conversation $conversation, AskQuestion $ask): JsonResponse
    {
        [$question, $answer] = $ask->followUp($conversation, $request->question());

        return response()->json(['data' => [
            'question' => new MessageResource($question),
            'answer' => new MessageResource($answer->load('citations')),
        ]], 202);
    }

    public function update(RenameConversationRequest $request, Conversation $conversation): ConversationResource
    {
        $conversation->update(['title' => $request->validated('title')]);

        return new ConversationResource($conversation);
    }

    /**
     * Deletes the conversation with its messages and citations. A pending
     * answer's job then finds nothing and stops quietly.
     */
    public function destroy(Conversation $conversation): Response
    {
        $conversation->delete();

        return response()->noContent();
    }

    /**
     * Poll one message (typically a pending answer).
     */
    public function message(Conversation $conversation, Message $message): MessageResource
    {
        return new MessageResource($message->load('citations'));
    }

    public function retryAnswer(Conversation $conversation, Message $message, AskQuestion $ask): JsonResponse
    {
        $answer = $ask->retryAnswer($conversation, $message);

        return (new MessageResource($answer->load('citations')))->response()->setStatusCode(202);
    }
}
