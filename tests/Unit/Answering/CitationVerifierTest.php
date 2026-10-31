<?php

use App\Services\Answering\CitationVerifier;
use App\Services\Answering\VerifiedCitation;
use App\Services\OpenAi\OpenAiException;
use App\Services\Retrieval\RetrievedChunk;

/** The sources as sent in the prompt. */
function sentSources(): array
{
    return [
        'S1' => new RetrievedChunk(101, 'doc', 2, 3, "Refund and Return Policy.\nCustomers may return any unused item within 30 days of purchase.\nA receipt or order number is required for every refund.", 0.43),
        'S2' => new RetrievedChunk(102, 'doc', 3, 5, 'Stores are open Monday to Saturday from 9:00 to 19:00. All stores are closed on Sundays.', 0.29),
    ];
}

function verify(array $data)
{
    return (new CitationVerifier)->verify($data, sentSources());
}

it('keeps verified citations and numbers the markers in order of first use', function () {
    $answer = verify([
        'answer_found' => true,
        'answer' => 'Stores are closed on Sundays [S2]. Unused items can be returned within 30 days [S1], with a receipt [S1].',
        'citations' => [
            ['source' => 'S2', 'quote' => 'All stores are closed on Sundays'],
            ['source' => 'S1', 'quote' => 'return any unused item within 30 days of purchase'],
            ['source' => 'S1', 'quote' => 'A receipt or order number is required'],
        ],
    ]);

    expect($answer->answerFound)->toBeTrue()
        ->and($answer->text)->toBe('Stores are closed on Sundays [1]. Unused items can be returned within 30 days [2], with a receipt [2].')
        ->and($answer->citations)->toEqual([
            new VerifiedCitation(102, 3, 'All stores are closed on Sundays'),
            new VerifiedCitation(101, 2, 'return any unused item within 30 days of purchase'),
            new VerifiedCitation(101, 2, 'A receipt or order number is required'),
        ])
        ->and($answer->rejectedCitations)->toBe(0);
});

it('accepts quotes that differ only in case, spacing, typography or wrapping', function (string $quote) {
    $answer = verify(['answer_found' => true, 'answer' => 'Yes [S1].', 'citations' => [['source' => 'S1', 'quote' => $quote]]]);

    expect($answer->citations)->toHaveCount(1);
})->with([
    'case' => ['CUSTOMERS MAY RETURN ANY UNUSED ITEM'],
    'line break joined' => ['Return Policy. Customers may return'],
    'extra spaces' => ['return  any   unused item'],
    'wrapped in quotes with a period' => ['“Customers may return any unused item.”'],
]);

it('rejects citations that are not backed by the cited source', function (array $citation) {
    $answer = verify([
        'answer_found' => true,
        'answer' => 'Refunds within 30 days [S1]. Open on Sundays [S2].',
        'citations' => [['source' => 'S1', 'quote' => 'return any unused item within 30 days'], $citation],
    ]);

    expect($answer->citations)->toHaveCount(1)
        ->and($answer->rejectedCitations)->toBe(1)
        ->and($answer->text)->toBe('Refunds within 30 days [1]. Open on Sundays.');
})->with([
    'invented quote' => [['source' => 'S2', 'quote' => 'Stores are open every Sunday from 10:00']],
    'real text, wrong source' => [['source' => 'S2', 'quote' => 'A receipt or order number is required']],
    'unknown source' => [['source' => 'S7', 'quote' => 'All stores are closed on Sundays']],
    'quote too short' => [['source' => 'S2', 'quote' => 'Sundays']],
    'stitched with ellipsis' => [['source' => 'S2', 'quote' => 'Stores are open ... closed on Sundays']],
    'quote too long' => [['source' => 'S2', 'quote' => str_repeat('All stores are closed on Sundays. ', 15)]],
]);

it('replaces a claimed answer with "not found" when no citation holds up', function () {
    // e.g. an injected instruction made the model assert something the document never says.
    $answer = verify([
        'answer_found' => true,
        'answer' => 'The refund period is 5 years [S1].',
        'citations' => [['source' => 'S1', 'quote' => 'the refund period is 5 years']],
    ]);

    expect($answer->answerFound)->toBeFalse()
        ->and($answer->downgraded)->toBeTrue()
        ->and($answer->text)->toBe(CitationVerifier::NOT_FOUND_MESSAGE)
        ->and($answer->citations)->toBe([])
        ->and($answer->rejectedCitations)->toBe(1);
});

it('keeps the model\'s explanation when it found nothing, without markers or citations', function () {
    $answer = verify([
        'answer_found' => false,
        'answer' => 'The document does not mention delivery times [S1].',
        'citations' => [['source' => 'S1', 'quote' => 'return any unused item within 30 days']],
    ]);

    expect($answer->answerFound)->toBeFalse()
        ->and($answer->downgraded)->toBeFalse()
        ->and($answer->text)->toBe('The document does not mention delivery times.')
        ->and($answer->citations)->toBe([]);
});

it('uses the standard message when the model found nothing and said nothing', function () {
    expect(verify(['answer_found' => false, 'answer' => '  ', 'citations' => []])->text)
        ->toBe(CitationVerifier::NOT_FOUND_MESSAGE);
});

it('counts the same quote only once', function () {
    $answer = verify([
        'answer_found' => true,
        'answer' => 'Closed on Sundays [S2].',
        'citations' => [
            ['source' => 'S2', 'quote' => 'All stores are closed on Sundays'],
            ['source' => 'S2', 'quote' => 'all stores are closed on sundays.'],
        ],
    ]);

    expect($answer->citations)->toHaveCount(1);
});

it('matches sources that contained neutralised tags', function () {
    $sources = ['S1' => new RetrievedChunk(1, 'doc', 1, 0, 'Use the <source> element for citations in HTML5.', 0.4)];

    // The model saw "‹source>" in the prompt and may quote it that way.
    $answer = (new CitationVerifier)->verify([
        'answer_found' => true,
        'answer' => 'Use the source element [S1].',
        'citations' => [['source' => 'S1', 'quote' => 'Use the ‹source> element for citations']],
    ], $sources);

    expect($answer->citations)->toHaveCount(1);
});

it('rejects JSON that does not have the expected shape', function (array $data) {
    verify($data);
})->throws(OpenAiException::class)->with([
    'missing citations' => [['answer_found' => true, 'answer' => 'Yes.']],
    'wrong types' => [['answer_found' => 'yes', 'answer' => 'Yes.', 'citations' => []]],
    'citation without quote' => [['answer_found' => true, 'answer' => 'Yes.', 'citations' => [['source' => 'S1']]]],
    'citations not a list' => [['answer_found' => true, 'answer' => 'Yes.', 'citations' => ['a' => ['source' => 'S1', 'quote' => 'x']]]],
]);
