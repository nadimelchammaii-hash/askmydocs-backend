<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\MessageCitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageCitation>
 */
class MessageCitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => Message::factory()->answer(),
            'document_chunk_id' => null,
            'position' => 0,
            'number' => fn (array $attributes) => $attributes['position'] + 1,
            'page_number' => fake()->numberBetween(1, 20),
            'quote' => fake()->sentence(),
        ];
    }
}
