<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory()->ready(),
            // Always the document's owner, as in the application.
            'user_id' => fn (array $attributes) => Document::findOrFail($attributes['document_id'])->user_id,
            'title' => rtrim(fake()->sentence(5), '.').'?',
        ];
    }
}
