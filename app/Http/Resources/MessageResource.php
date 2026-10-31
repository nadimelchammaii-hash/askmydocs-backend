<?php

namespace App\Http\Resources;

use App\Enums\MessageRole;
use App\Models\Message;
use App\Models\MessageCitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Answer text uses markers [1], [2], ... that refer to `citations[].number`.
 * Content is plain text (it may quote the document): clients must never
 * render it as HTML or Markdown.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAnswer = $this->role === MessageRole::Assistant;

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'is_finished' => $this->status->isFinished(),
            'content' => $this->content,
            'answer_found' => $isAnswer ? $this->answer_found : null,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'citations' => $isAnswer
                ? $this->citations->map(fn (MessageCitation $citation) => [
                    'number' => $citation->position + 1,
                    'page_number' => $citation->page_number,
                    'quote' => $citation->quote,
                ])->all()
                : [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
