<?php

namespace App\Http\Requests\Documents;

use App\Enums\DocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDocumentsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(DocumentStatus::class)],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * The search text as a LIKE pattern, with the user's % and _ taken literally.
     */
    public function searchPattern(): ?string
    {
        $search = trim((string) $this->validated('search'));

        return $search === '' ? null : '%'.addcslashes($search, '\\%_').'%';
    }
}
