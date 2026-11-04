<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentChunk>
 */
class DocumentChunkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = fake()->paragraph();

        return [
            'document_id' => Document::factory()->ready(),
            'chunk_index' => 0,
            'page_number' => 1,
            'content' => $content,
            'char_count' => mb_strlen($content),
            'embedding' => self::randomUnitVector(),
        ];
    }

    /**
     * A random vector of length 1, like OpenAI's (normalised) embeddings.
     *
     * @return list<float>
     */
    public static function randomUnitVector(int $dimensions = 1536): array
    {
        $vector = array_map(fn () => fake()->randomFloat(6, -1, 1), range(1, $dimensions));
        $norm = sqrt(array_sum(array_map(fn (float $x) => $x * $x, $vector))) ?: 1.0;

        return array_map(fn (float $x) => $x / $norm, $vector);
    }
}
