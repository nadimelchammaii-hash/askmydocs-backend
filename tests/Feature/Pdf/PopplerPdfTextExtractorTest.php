<?php

use App\Services\Pdf\PdfExtractionException;
use App\Services\Pdf\PdfTextExtractor;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Support\PdfFixture;

function extractor(): PdfTextExtractor
{
    return app(PdfTextExtractor::class);
}

function extractionError(Closure $callback): PdfExtractionException
{
    try {
        $callback();
    } catch (PdfExtractionException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected a PdfExtractionException.');
}

function popplerAvailable(): bool
{
    try {
        return Process::run([config('documents.pdftotext_binary'), '-v'])->successful();
    } catch (Throwable) {
        return false;
    }
}

describe('with faked Poppler processes', function () {
    beforeEach(fn () => Process::preventStrayProcesses());

    it('reads the page count with pdfinfo', function () {
        Process::fake(['*' => Process::result("Title: Report\nPages:          12\nEncrypted:      no\n")]);

        expect(extractor()->inspect('/tmp/report.pdf')->pages)->toBe(12);

        Process::assertRan(fn ($process) => $process->command[0] === config('documents.pdfinfo_binary')
            && $process->command[1] === '/tmp/report.pdf'
            && $process->timeout === config('documents.process_timeout'));
    });

    it('splits pdftotext output into pages and keeps empty pages in place', function () {
        Process::fake(['*' => Process::result(
            "Introduction to the annual report.\n\fFinancial results by quarter\n\f\fAppendix with definitions.\n\f",
        )]);

        expect(extractor()->extractPages('/tmp/report.pdf'))
            ->toBe(['Introduction to the annual report.', 'Financial results by quarter', '', 'Appendix with definitions.']);
    });

    it('keeps an empty last page', function () {
        Process::fake(['*' => Process::result("Introduction to the annual report.\n\fBack cover follows.\n\f\f")]);

        expect(extractor()->extractPages('/tmp/report.pdf'))
            ->toBe(['Introduction to the annual report.', 'Back cover follows.', '']);
    });

    it('cleans text so it is valid UTF-8 without control characters or excess whitespace', function () {
        $dirty = "Total:\0 42\x01 EUR\r\nNext   line\t\twith\u{00A0}spaces\n\n\n\nInvalid \xC3 byte  \n";
        Process::fake(['*' => Process::result($dirty."\f")]);

        $text = extractor()->extractPages('/tmp/report.pdf')[0];

        expect($text)->toBe("Total: 42 EUR\nNext line with spaces\n\nInvalid ? byte")
            ->and(mb_check_encoding($text, 'UTF-8'))->toBeTrue();
    });

    it('rejects documents without a text layer', function () {
        Process::fake(['*' => Process::result("\f  \f1\f\f")]);

        $error = extractionError(fn () => extractor()->extractPages('/tmp/scan.pdf'));

        expect($error->reason)->toBe(PdfExtractionException::NO_TEXT_LAYER)
            ->and($error->isPermanent())->toBeTrue();
    });

    it('maps Poppler failures to permanent or transient errors', function (int $exitCode, string $stderr, string $reason, bool $permanent) {
        Process::fake(['*' => Process::result('', $stderr, $exitCode)]);

        $error = extractionError(fn () => extractor()->inspect('/tmp/file.pdf'));

        expect($error->reason)->toBe($reason)
            ->and($error->isPermanent())->toBe($permanent);
    })->with([
        'password required' => [1, 'Command Line Error: Incorrect password', PdfExtractionException::ENCRYPTED_PDF, true],
        'corrupt file' => [1, "Syntax Error: Couldn't read xref table", PdfExtractionException::INVALID_PDF, true],
        'copying not allowed' => [3, 'Permission Error: Copying of text from this document is not allowed.', PdfExtractionException::EXTRACTION_NOT_PERMITTED, true],
        'unexpected tool error' => [99, 'Out of memory', PdfExtractionException::EXTRACTION_FAILED, false],
    ]);

    it('treats a timeout as transient so the job can retry', function () {
        Process::fake(function () {
            $process = new SymfonyProcess(['pdftotext']);

            throw new ProcessTimedOutException(
                new SymfonyTimeoutException($process, SymfonyTimeoutException::TYPE_GENERAL),
                Process::result(),
            );
        });

        $error = extractionError(fn () => extractor()->extractPages('/tmp/huge.pdf'));

        expect($error->reason)->toBe(PdfExtractionException::EXTRACTION_TIMEOUT)
            ->and($error->isPermanent())->toBeFalse();
    });

    it('rejects pdfinfo output without a page count', function () {
        Process::fake(['*' => Process::result("Title: something\n")]);

        expect(extractionError(fn () => extractor()->inspect('/tmp/odd.pdf'))->reason)
            ->toBe(PdfExtractionException::INVALID_PDF);
    });
});

describe('with the real Poppler binaries', function () {
    $files = [];

    afterEach(function () use (&$files) {
        array_map(fn (string $file) => @unlink($file), $files);
        $files = [];
    });

    it('extracts each page with the right page number', function () use (&$files) {
        $files[] = $path = PdfFixture::file([
            "Annual report 2025.\nRevenue grew by 12 percent.",
            'The refund policy allows returns within 30 days.',
            'Annual costs were 4.2 million euros.',
        ]);

        expect(extractor()->inspect($path)->pages)->toBe(3);

        $pages = extractor()->extractPages($path);

        expect($pages)->toHaveCount(3)
            ->and($pages[0])->toBe("Annual report 2025.\nRevenue grew by 12 percent.")
            ->and($pages[1])->toContain('refund policy')
            ->and($pages[2])->toContain('4.2 million');
    });

    it('detects a PDF whose pages contain no text', function () use (&$files) {
        $files[] = $path = PdfFixture::file(['', '', '']);

        expect(extractionError(fn () => extractor()->extractPages($path))->reason)
            ->toBe(PdfExtractionException::NO_TEXT_LAYER);
    });

    it('rejects a file that is not a PDF', function () use (&$files) {
        $files[] = $path = tempnam(sys_get_temp_dir(), 'not-a-pdf-');
        file_put_contents($path, "MZ\x90\x00 this is an executable, renamed to .pdf");

        expect(extractionError(fn () => extractor()->inspect($path))->reason)
            ->toBe(PdfExtractionException::INVALID_PDF);
    });

    it('handles paths containing spaces', function () use (&$files) {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pdf fixture '.uniqid();
        mkdir($directory);
        $path = $directory.DIRECTORY_SEPARATOR.'my report.pdf';
        file_put_contents($path, PdfFixture::bytes(['Text in a folder with spaces.']));
        $files[] = $path;

        try {
            expect(extractor()->extractPages($path))->toBe(['Text in a folder with spaces.']);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    });
})->skip(fn () => ! popplerAvailable(), 'Poppler (pdftotext) is not installed; see README.');
