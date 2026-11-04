<?php

namespace App\Services\Embeddings;

use App\Services\OpenAi\OpenAiException;

/**
 * Turns texts into embedding vectors.
 *
 * Implementations: OpenAiEmbeddingClient (real API); tests use a fake.
 */
interface EmbeddingClient
{
    /**
     * Embeds the texts, in the given order (vectors[i] belongs to texts[i]).
     *
     * @param  list<string>  $texts  non-empty strings
     *
     * @throws OpenAiException when the provider fails or answers unexpectedly
     */
    public function embed(array $texts): EmbeddingResult;

    /** The model name recorded on documents (documents.embedding_model). */
    public function model(): string;
}
