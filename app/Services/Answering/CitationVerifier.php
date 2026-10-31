<?php

namespace App\Services\Answering;

use App\Services\OpenAi\OpenAiException;
use App\Services\Retrieval\RetrievedChunk;

/**
 * Turns the model's JSON into an answer we can stand behind.
 *
 * A citation is kept only if its label was sent and its quote appears in that
 * source's text (ignoring case, spacing, quote and dash styles). Markers in the
 * answer are rewritten to [1], [2], ... (one number per cited source, shared by
 * all its quotes) and removed for sources without a kept citation. An answer that claims to be found but keeps no citation is
 * replaced by the "not found" message: an unsupported answer is worse than none.
 */
class CitationVerifier
{
    public const NOT_FOUND_MESSAGE = 'I could not find this information in the document.';

    private const MIN_QUOTE_CHARS = 10;

    private const MAX_QUOTE_CHARS = 400;

    /**
     * @param  array<string, mixed>  $data  the model's JSON
     * @param  array<string, RetrievedChunk>  $sources  label => chunk, as sent
     *
     * @throws OpenAiException when the JSON does not have the expected shape
     */
    public function verify(array $data, array $sources): VerifiedAnswer
    {
        [$found, $answer, $citations] = $this->shape($data);

        /** @var list<VerifiedCitation> $kept */
        $kept = [];
        /** @var array<string, int> label => marker number (1, 2, ... in order of first kept citation) */
        $numbers = [];
        $seen = [];
        $rejected = 0;

        foreach ($found ? $citations : [] as $citation) {
            $label = $citation['source'];
            $chunk = $sources[$label] ?? null;
            $quote = trim($citation['quote']);
            $key = $label.'|'.self::normalise($quote);

            if ($chunk === null || ! $this->quoteIsIn($quote, $chunk->content)) {
                $rejected++;

                continue;
            }

            if (isset($seen[$key])) {
                continue; // the same quote twice: keep one
            }

            $seen[$key] = true;
            $numbers[$label] ??= count($numbers) + 1;
            $kept[] = new VerifiedCitation($numbers[$label], $chunk->chunkId, $chunk->pageNumber, $quote);
        }

        if (! $found) {
            return new VerifiedAnswer(false, $this->text($answer, []) ?: self::NOT_FOUND_MESSAGE, [], count($citations), false);
        }

        if ($kept === []) {
            return new VerifiedAnswer(false, self::NOT_FOUND_MESSAGE, [], $rejected, true);
        }

        return new VerifiedAnswer(true, $this->text($answer, $numbers), $kept, $rejected, false);
    }

    /**
     * Rewrites [S2] to its citation number, drops markers without a kept
     * citation, and tidies the spaces they leave behind.
     *
     * @param  array<string, int>  $numbers
     */
    private function text(string $answer, array $numbers): string
    {
        $text = preg_replace_callback(
            '/\s*\[(S\d+)\]/u',
            fn (array $m) => isset($numbers[$m[1]]) ? " [{$numbers[$m[1]]}]" : '',
            $answer,
        ) ?? '';

        $text = preg_replace('/ +([.,;:!?])/u', '$1', $text) ?? '';
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text) ?? '';

        return trim($text);
    }

    private function quoteIsIn(string $quote, string $source): bool
    {
        $quote = self::normalise($quote);
        $length = mb_strlen($quote);

        return $length >= self::MIN_QUOTE_CHARS
            && $length <= self::MAX_QUOTE_CHARS
            && str_contains(self::normalise($source), $quote);
    }

    /**
     * Case-, spacing- and typography-insensitive form for comparing quotes.
     */
    public static function normalise(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, [
            '‘' => "'", '’' => "'", '‚' => "'", '‛' => "'", '`' => "'",
            '“' => '"', '”' => '"', '„' => '"', '«' => '"', '»' => '"',
            '‐' => '-', '‑' => '-', '‒' => '-', '–' => '-', '—' => '-', '―' => '-',
            '…' => '...', '‹' => '<', '›' => '>', "\u{00A0}" => ' ',
        ]);
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        // Models often wrap quotes in quotation marks or end them with a period.
        return trim($text, " \"'.,;:");
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{bool, string, list<array{source: string, quote: string}>}
     */
    private function shape(array $data): array
    {
        $citations = $data['citations'] ?? null;

        $valid = is_bool($data['answer_found'] ?? null)
            && is_string($data['answer'] ?? null)
            && is_array($citations)
            && array_is_list($citations)
            && array_all($citations, fn ($c) => is_array($c) && is_string($c['source'] ?? null) && is_string($c['quote'] ?? null));

        if (! $valid) {
            throw OpenAiException::invalidResponse('answer JSON does not match the schema');
        }

        return [$data['answer_found'], $data['answer'], $citations];
    }
}
