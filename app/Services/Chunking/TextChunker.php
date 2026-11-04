<?php

namespace App\Services\Chunking;

use InvalidArgumentException;

/**
 * Splits page texts into overlapping chunks for embedding.
 *
 * Rules:
 *  - A chunk never spans two pages, so every citation points to one exact page.
 *  - A page up to `maxChars` long becomes a single chunk.
 *  - Longer pages are cut near `targetChars`, preferring (in this order) a
 *    paragraph break, a sentence end, a line break, then a space. Only a single
 *    "word" longer than `maxChars` is ever cut in the middle.
 *  - Consecutive chunks of a page overlap by up to `overlapChars`, starting at a
 *    sentence start when possible, so a sentence cut at a boundary still appears
 *    whole in one of the two chunks.
 *  - Chunks are exact excerpts of the page (only trimmed), never rewritten.
 *
 * Lengths are counted in characters, not bytes, so non-Latin text is sized fairly.
 */
final class TextChunker
{
    /** Boundary kinds, strongest first. Each pattern's match end is a cut position. */
    private const BOUNDARIES = [
        'paragraph' => '/\n[ \t]*\n\s*/u',
        'sentence' => '/[.!?…]+["\'”’)\]]*\s+/u',
        'line' => '/\n\s*/u',
        'space' => '/\s+/u',
    ];

    private readonly int $minChars;

    public function __construct(
        private readonly int $targetChars = 1500,
        private readonly int $maxChars = 2000,
        private readonly int $overlapChars = 200,
    ) {
        // A cut is never placed before minChars, so chunks are not tiny (except a page's last one).
        $this->minChars = intdiv($targetChars, 2);

        if ($targetChars < 2 || $targetChars > $maxChars) {
            throw new InvalidArgumentException('Chunk target size must be at least 2 and at most the maximum size.');
        }

        if ($overlapChars < 0 || $overlapChars >= $this->minChars) {
            throw new InvalidArgumentException('Chunk overlap must be smaller than half the target size, or chunks would not advance.');
        }
    }

    /**
     * @param  array<int, string>  $pages  page text keyed by 1-based page number
     * @return list<Chunk>
     */
    public function chunk(array $pages): array
    {
        ksort($pages);
        $chunks = [];

        foreach ($pages as $pageNumber => $text) {
            foreach ($this->splitPage($text) as $content) {
                $chunks[] = new Chunk(count($chunks), $pageNumber, $content);
            }
        }

        return $chunks;
    }

    /**
     * @return list<string>
     */
    private function splitPage(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $chars = mb_str_split($text);
        $length = count($chars);
        $boundaries = $this->findBoundaries($text, $chars);
        $pieces = [];
        $start = 0;

        while ($start < $length) {
            $end = $length - $start <= $this->maxChars ? $length : $this->cutPosition($start, $boundaries);

            $piece = trim(implode('', array_slice($chars, $start, $end - $start)));
            if ($piece !== '') {
                $pieces[] = $piece;
            }

            if ($end >= $length) {
                break;
            }

            $start = $this->nextStart($end, $boundaries);
        }

        return $pieces;
    }

    /**
     * Where to end a chunk that starts at $start: the strongest boundary within the
     * target size, else within the maximum size, else a hard cut at the maximum.
     *
     * @param  array<string, list<int>>  $boundaries
     */
    private function cutPosition(int $start, array $boundaries): int
    {
        foreach ([$this->targetChars, $this->maxChars] as $limit) {
            foreach ($boundaries as $positions) {
                $cut = $this->lastBetween($positions, $start + $this->minChars, $start + $limit);
                if ($cut !== null) {
                    return $cut;
                }
            }
        }

        return $start + $this->maxChars;
    }

    /**
     * Start of the next chunk: within the overlap window before $end, at a
     * sentence start if possible, else at a word start, else exactly at $end.
     *
     * @param  array<string, list<int>>  $boundaries
     */
    private function nextStart(int $end, array $boundaries): int
    {
        if ($this->overlapChars === 0) {
            return $end;
        }

        $from = $end - $this->overlapChars;

        return $this->firstBetween($boundaries['sentence'], $from, $end - 1)
            ?? $this->firstBetween($boundaries['space'], $from, $end - 1)
            ?? $end;
    }

    /**
     * Cut positions (character offsets) per boundary kind, in ascending order.
     *
     * @param  list<string>  $chars
     * @return array<string, list<int>>
     */
    private function findBoundaries(string $text, array $chars): array
    {
        // preg_* reports byte offsets; map them to character offsets.
        $byteToChar = [];
        $byte = 0;
        foreach ($chars as $index => $char) {
            $byteToChar[$byte] = $index;
            $byte += strlen($char);
        }
        $byteToChar[$byte] = count($chars);

        $boundaries = [];
        foreach (self::BOUNDARIES as $kind => $pattern) {
            preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
            $boundaries[$kind] = array_map(
                fn (array $match): int => $byteToChar[$match[1] + strlen($match[0])],
                $matches[0],
            );
        }

        return $boundaries;
    }

    /**
     * @param  list<int>  $positions  ascending
     */
    private function lastBetween(array $positions, int $min, int $max): ?int
    {
        $found = null;
        foreach ($positions as $position) {
            if ($position > $max) {
                break;
            }
            if ($position >= $min) {
                $found = $position;
            }
        }

        return $found;
    }

    /**
     * @param  list<int>  $positions  ascending
     */
    private function firstBetween(array $positions, int $min, int $max): ?int
    {
        foreach ($positions as $position) {
            if ($position > $max) {
                return null;
            }
            if ($position >= $min) {
                return $position;
            }
        }

        return null;
    }
}
