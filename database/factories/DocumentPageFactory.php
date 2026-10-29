<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentPage>
 */
class DocumentPageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = fake()->paragraphs(3, true);

        return [
            'document_id' => Document::factory(),
            'page_number' => 1,
            'text' => $text,
            'char_count' => mb_strlen($text),
        ];
    }
}
