<?php

namespace App\Services\OpenAi;

/**
 * Asks a language model for a response that follows a JSON schema.
 *
 * Implementations: OpenAiResponsesClient (real API); tests use a fake.
 */
interface StructuredOutputClient
{
    /**
     * @param  string  $instructions  trusted rules (developer message)
     * @param  string  $input  the request content; may contain untrusted text
     *
     * @throws OpenAiException when the call fails or yields no usable JSON
     */
    public function generate(string $instructions, string $input, JsonSchema $schema): StructuredOutput;

    /** The model name recorded on answers (messages.model). */
    public function model(): string;
}
