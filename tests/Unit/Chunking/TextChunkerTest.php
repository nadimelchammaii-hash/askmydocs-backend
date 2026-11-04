<?php

use App\Services\Chunking\Chunk;
use App\Services\Chunking\TextChunker;

/** Small sizes keep the examples readable: cut near 100, never above 140, overlap up to 20. */
function smallChunker(): TextChunker
{
    return new TextChunker(targetChars: 100, maxChars: 140, overlapChars: 20);
}

/** @return list<string> */
function contents(array $chunks): array
{
    return array_map(fn (Chunk $chunk) => $chunk->content, $chunks);
}

/** A sentence of exactly $length characters (including the final period). */
function sentence(int $length, string $letter = 'a'): string
{
    $words = '';
    while (mb_strlen($words) < $length - 1) {
        $words .= ($words === '' ? '' : ' ').str_repeat($letter, 4);
    }

    return mb_substr($words, 0, $length - 1).'.';
}

/**
 * The chunker's guarantees: every piece is non-empty, at most $max characters,
 * an exact excerpt of the page, pieces appear in page order, and together
 * they cover every non-whitespace character of the page.
 *
 * @param  list<string>  $pieces
 */
function assertCoversPage(string $page, array $pieces, int $max): void
{
    $chars = mb_str_split($page);
    $covered = array_fill(0, count($chars), false);
    $searchFrom = 0;

    foreach ($pieces as $piece) {
        expect($piece)->not->toBe('')
            ->and(mb_check_encoding($piece, 'UTF-8'))->toBeTrue()
            ->and(mb_strlen($piece))->toBeLessThanOrEqual($max);

        $position = mb_strpos($page, $piece, $searchFrom);
        expect($position)->not->toBeFalse("Chunk is not an excerpt of the page (in order): {$piece}");

        for ($i = $position; $i < $position + mb_strlen($piece); $i++) {
            $covered[$i] = true;
        }
        $searchFrom = $position + 1;
    }

    foreach ($chars as $i => $char) {
        if (! $covered[$i] && trim($char) !== '') {
            throw new RuntimeException("Character {$i} ('{$char}') is not in any chunk.");
        }
    }
}

it('keeps a short page as a single chunk', function () {
    $chunks = smallChunker()->chunk([1 => "  A short page.\nWith two lines.  "]);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0])->toEqual(new Chunk(0, 1, "A short page.\nWith two lines."));
});

it('numbers chunks across pages, never mixes pages and skips empty ones', function () {
    $chunks = smallChunker()->chunk([
        3 => 'Third page.',
        1 => 'First page.',
        2 => "  \n ",
        4 => '',
    ]);

    expect(array_map(fn (Chunk $c) => [$c->index, $c->pageNumber, $c->content], $chunks))->toBe([
        [0, 1, 'First page.'],
        [1, 3, 'Third page.'],
    ]);
});

it('keeps a page of exactly the maximum size whole', function () {
    $page = sentence(140);

    expect(contents(smallChunker()->chunk([1 => $page])))->toBe([$page]);
});

it('prefers a paragraph break over a later sentence end', function () {
    $first = sentence(60, 'a').' '.sentence(20, 'b');      // 81 chars
    $second = sentence(15, 'c').' '.sentence(70, 'd');     // 86 chars
    $page = "{$first}\n\n{$second}";

    $chunks = contents(smallChunker()->chunk([1 => $page]));

    expect($chunks[0])->toBe($first);
});

it('cuts at a sentence end when there is no paragraph break', function () {
    $sentences = [sentence(40, 'a'), sentence(40, 'b'), sentence(40, 'c'), sentence(40, 'd')];
    $page = implode(' ', $sentences); // 163 chars, one paragraph

    $chunks = contents(smallChunker()->chunk([1 => $page]));

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0])->toBe("{$sentences[0]} {$sentences[1]}")
        // No sentence starts within the 20-character overlap window, so the overlap
        // starts at a word: the last words of sentence b are repeated.
        ->and($chunks[1])->toBe("bbbb bbbb bbbb. {$sentences[2]} {$sentences[3]}");
});

it('overlaps consecutive chunks so a sentence at the edge appears whole', function () {
    // Sentence ends at 45, 81 and 97 characters: the cut is after c (the last end within 100),
    // and c starts within the 20-character overlap window.
    $sentences = [sentence(45, 'a'), sentence(35, 'b'), sentence(15, 'c'), sentence(60, 'd')];
    $page = implode(' ', $sentences);

    $chunks = contents(smallChunker()->chunk([1 => $page]));

    // Cut after the short third sentence; it is repeated as the start of the next chunk.
    expect($chunks[0])->toEndWith($sentences[2])
        ->and($chunks[1])->toBe("{$sentences[2]} {$sentences[3]}");
});

it('splits text without punctuation at spaces, never inside a word', function () {
    $words = array_map(fn (int $i) => "word{$i}", range(1, 60));
    $page = implode(' ', $words);

    $chunks = contents(smallChunker()->chunk([1 => $page]));

    expect(count($chunks))->toBeGreaterThan(1);
    foreach ($chunks as $chunk) {
        foreach (explode(' ', $chunk) as $word) {
            expect($words)->toContain($word);
        }
    }
    assertCoversPage($page, $chunks, 140);
});

it('hard-cuts a single word longer than the maximum, losing nothing', function () {
    $page = str_repeat('x', 350);

    $chunks = contents(smallChunker()->chunk([1 => $page]));

    expect(array_map('mb_strlen', $chunks))->toBe([140, 140, 70])
        ->and(implode('', $chunks))->toBe($page);
});

it('measures characters, not bytes, and keeps UTF-8 intact', function () {
    // 2-, 3- and 4-byte characters (numbered, so no two phrases are identical).
    $page = implode(' ', array_map(fn (int $i) => "éèàüñ{$i} 漢字😀.", range(1, 30)));

    $chunks = contents(smallChunker()->chunk([1 => $page]));

    assertCoversPage($page, $chunks, 140);
    // The longest chunk is over the 140 limit in bytes, which only works if sizes are in characters.
    $lengths = array_map('mb_strlen', $chunks);
    $longestChunk = $chunks[array_search(max($lengths), $lengths, true)];
    expect(max($lengths))->toBeGreaterThan(90)
        ->and(strlen($longestChunk))->toBeGreaterThan(140);
});

it('produces the same chunks for the same input', function () {
    $page = implode(' ', array_map(fn (int $i) => sentence(30 + $i % 20, chr(97 + $i % 26)), range(1, 30)));

    expect(smallChunker()->chunk([1 => $page]))->toEqual(smallChunker()->chunk([1 => $page]));
});

it('keeps its guarantees on varied realistic pages with the default sizes', function () {
    mt_srand(20261031); // reproducible
    $vocabulary = ['the', 'refund', 'policy', 'allows', 'returns', 'within', 'days', 'revenue', 'grew',
        'percent', 'Müller', 'résumé', '東京', 'naïve', 'Q4', '4.2', 'e.g.', '(see', 'appendix)', '"quoted"'];
    $chunker = new TextChunker;

    for ($page = 0; $page < 50; $page++) {
        $text = '';
        $paragraphs = mt_rand(1, 8);
        for ($p = 0; $p < $paragraphs; $p++) {
            $sentences = mt_rand(1, 12);
            for ($s = 0; $s < $sentences; $s++) {
                $words = [];
                $count = mt_rand(1, mt_rand(0, 9) === 0 ? 400 : 30); // occasionally a very long sentence
                for ($w = 0; $w < $count; $w++) {
                    $words[] = $vocabulary[mt_rand(0, count($vocabulary) - 1)];
                }
                $text .= ucfirst(implode(mt_rand(0, 15) === 0 ? "\n" : ' ', $words)).['.', '!', '?'][mt_rand(0, 2)].' ';
            }
            $text .= "\n\n";
        }

        $chunks = contents($chunker->chunk([1 => $text]));
        assertCoversPage($text, $chunks, 2000);

        // All chunks but the last are reasonably sized (not tiny fragments).
        foreach (array_slice($chunks, 0, -1) as $chunk) {
            expect(mb_strlen($chunk))->toBeGreaterThanOrEqual(700);
        }
    }
});

it('rejects settings that could not make progress', function (int $target, int $max, int $overlap) {
    new TextChunker($target, $max, $overlap);
})->throws(InvalidArgumentException::class)->with([
    'target above max' => [2100, 2000, 200],
    'overlap of half the target' => [1500, 2000, 750],
    'negative overlap' => [1500, 2000, -1],
    'tiny target' => [1, 2000, 0],
]);
