<?php

namespace App\Http\Requests\Conversations;

use Illuminate\Foundation\Http\FormRequest;

class RenameConversationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
        ];
    }
}
