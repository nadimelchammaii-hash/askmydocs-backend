<?php

namespace App\Http\Requests\Conversations;

use Illuminate\Foundation\Http\FormRequest;

class AskQuestionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Empty and whitespace-only questions arrive as null (TrimStrings / ConvertEmptyStringsToNull).
        return [
            'question' => ['required', 'string', 'max:'.config('answers.max_question_chars')],
        ];
    }

    public function question(): string
    {
        return (string) $this->validated('question');
    }
}
