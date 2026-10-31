<?php

namespace Database\Factories;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * A user's question by default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'role' => MessageRole::User,
            'status' => MessageStatus::Completed,
            'content' => rtrim(fake()->sentence(), '.').'?',
        ];
    }

    public function pendingAnswer(): static
    {
        return $this->state(fn () => [
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Pending,
            'content' => null,
        ]);
    }

    public function answer(bool $found = true): static
    {
        return $this->state(fn () => [
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Completed,
            'content' => $found ? fake()->paragraph() : 'I could not find this in the document.',
            'answer_found' => $found,
            'model' => 'gpt-6-luna',
            'input_tokens' => 3000,
            'output_tokens' => 300,
            'duration_ms' => 2500,
        ]);
    }

    public function failedAnswer(string $code = 'answer_failed'): static
    {
        return $this->state(fn () => [
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Failed,
            'content' => null,
            'error_code' => $code,
            'error_message' => 'The answer could not be generated. Please try again.',
        ]);
    }
}
