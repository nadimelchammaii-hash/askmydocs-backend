<?php

namespace App\Services\Answering;

/**
 * An answer whose citations were all checked against the source text.
 * `text` uses markers [1], [2], ... that refer to `citations[].number`; all
 * quotes from one source share its number.
 */
final readonly class VerifiedAnswer
{
    public function __construct(
        public bool $answerFound,
        public string $text,
        /** @var list<VerifiedCitation> */
        public array $citations,
        /** Citations returned by the model but rejected (for logs). */
        public int $rejectedCitations,
        /** The model claimed an answer, but no citation could be verified. */
        public bool $downgraded,
    ) {}
}
