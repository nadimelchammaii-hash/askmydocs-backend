<?php

namespace Tests\Support;

use App\Services\OpenAi\JsonSchema;
use App\Services\OpenAi\OpenAiException;
use App\Services\OpenAi\StructuredOutput;
use App\Services\OpenAi\StructuredOutputClient;
use Closure;

/**
 * Stands in for the answering model. Returns queued replies in order (an array
 * of JSON data, an exception to throw, or a callback receiving the input), and
 * records every call for assertions.
 */
final class FakeStructuredOutputClient implements StructuredOutputClient
{
    /** @var list<array{instructions: string, input: string, schema: JsonSchema}> */
    public array $calls = [];

    /** @var list<array<string, mixed>|OpenAiException|Closure> */
    private array $replies = [];

    public static function bind(): self
    {
        $fake = new self;
        app()->instance(StructuredOutputClient::class, $fake);

        return $fake;
    }

    /** @param array<string, mixed>|OpenAiException|Closure(string): array<string, mixed> $reply */
    public function reply(array|OpenAiException|Closure $reply): self
    {
        $this->replies[] = $reply;

        return $this;
    }

    public function generate(string $instructions, string $input, JsonSchema $schema): StructuredOutput
    {
        $this->calls[] = compact('instructions', 'input', 'schema');
        $reply = array_shift($this->replies) ?? throw new \LogicException('FakeStructuredOutputClient has no reply queued.');

        $data = match (true) {
            $reply instanceof OpenAiException => throw $reply,
            $reply instanceof Closure => $reply($input),
            default => $reply,
        };

        return new StructuredOutput($data, $this->model(), inputTokens: 1200, outputTokens: 150, durationMs: 800);
    }

    public function model(): string
    {
        return 'fake-answer-model';
    }
}
