<?php

namespace App\Http\Controllers;

use App\Actions\Conversations\AskQuestion;
use App\Http\Requests\Conversations\AskQuestionRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use Illuminate\Http\JsonResponse;

/**
 * Asking questions. Answers are generated in the background: these endpoints
 * return 202 with a pending answer, which the client polls until is_finished.
 * Ownership is enforced by the routes (404 for other users' resources).
 */
class ConversationController extends Controller
{
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

    /**
     * Poll one message (typically a pending answer).
     */
    public function message(Conversation $conversation, Message $message): MessageResource
    {
        return new MessageResource($message->load('citations'));
    }
}
