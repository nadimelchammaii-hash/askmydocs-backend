<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title(fake()->words(3, true));

        return [
            'user_id' => User::factory(),
            'title' => $title,
            'original_filename' => Str::slug($title).'.pdf',
            'storage_path' => fn (array $attributes) => $attributes['user_id'].'/'.Str::ulid().'.pdf',
            'size_bytes' => fake()->numberBetween(10_000, 5_000_000),
            'sha256' => hash('sha256', Str::random(40)),
            'status' => DocumentStatus::Queued,
        ];
    }

    public function ready(int $pages = 3): static
    {
        return $this->state(fn () => [
            'status' => DocumentStatus::Ready,
            'page_count' => $pages,
            'processing_started_at' => now()->subMinute(),
            'processed_at' => now(),
        ]);
    }

    public function failed(string $code = 'no_text_layer', string $message = 'This PDF does not contain extractable text.'): static
    {
        return $this->state(fn () => [
            'status' => DocumentStatus::Failed,
            'error_code' => $code,
            'error_message' => $message,
            'processing_started_at' => now()->subMinute(),
        ]);
    }
}
