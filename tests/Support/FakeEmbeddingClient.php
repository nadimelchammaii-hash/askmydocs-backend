<?php

namespace Tests\Support;

use App\Services\Embeddings\EmbeddingClient;
use App\Services\Embeddings\EmbeddingException;
use App\Services\Embeddings\EmbeddingResult;

/**
 * Stands in for OpenAI. Returns a deterministic unit vector per text (the same
 * text always gets the same vector), or a vector set explicitly with
 * vectorFor(), so retrieval tests can control similarity exactly.
 */
final class FakeEmbeddingClient implements EmbeddingClient
{
    /** @var list<list<string>> texts of every embed() call */
    public array $calls = [];

    /** @var array<string, list<float>> */
    private array $vectors = [];

    private ?EmbeddingException $failure = null;

    public function __construct(private readonly int $dimensions = 1536) {}

    public static function bind(int $dimensions = 1536): self
    {
        $fake = new self($dimensions);
        app()->instance(EmbeddingClient::class, $fake);

        return $fake;
    }

    /** Texts containing $needle get $vector (normalised); the first match wins. */
    public function vectorFor(string $needle, array $vector): self
    {
        $this->vectors[$needle] = self::normalise(array_pad($vector, $this->dimensions, 0.0));

        return $this;
    }

    public function failWith(EmbeddingException $exception): self
    {
        $this->failure = $exception;

        return $this;
    }

    public function embed(array $texts): EmbeddingResult
    {
        $this->calls[] = $texts;

        if ($this->failure) {
            throw $this->failure;
        }

        return new EmbeddingResult(
            array_map($this->vectorOf(...), $texts),
            array_sum(array_map(fn (string $t) => (int) ceil(mb_strlen($t) / 4), $texts)),
            $this->model(),
        );
    }

    public function model(): string
    {
        return 'fake-embedding-model';
    }

    /** @return list<float> */
    private function vectorOf(string $text): array
    {
        foreach ($this->vectors as $needle => $vector) {
            if (str_contains($text, $needle)) {
                return $vector;
            }
        }

        // Deterministic pseudo-random vector derived from the text.
        mt_srand(crc32($text));
        $vector = array_map(fn () => mt_rand(-1000, 1000) / 1000, range(1, $this->dimensions));
        mt_srand();

        return self::normalise($vector);
    }

    /** @return list<float> */
    private static function normalise(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $vector))) ?: 1.0;

        return array_map(fn ($x) => $x / $norm, $vector);
    }
}
