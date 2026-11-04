<?php

namespace App\Http\Requests\Conversations;

use Illuminate\Foundation\Http\FormRequest;

class ListConversationsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Only the user's own conversations are listed, so another user's
            // document ID simply yields an empty list.
            'document_id' => ['sometimes', 'string', 'ulid'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
