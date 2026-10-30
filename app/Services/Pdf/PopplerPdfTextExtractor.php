<?php

namespace App\Services\Pdf;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

/**
 * PdfTextExtractor backed by Poppler's command-line tools (pdfinfo, pdftotext).
 *
 * pdftotext writes a form feed (\f) after every page, so splitting its output
 * on \f yields the text of each page in order — which is what makes page-level
 * citations possible.
 */
class PopplerPdfTextExtractor implements PdfTextExtractor
{
    /**
     * Below this average of visible characters per page, the PDF is treated as
     * having no text layer (typically a scanned document). A heuristic: a few
     * stray characters (page numbers, a header) must not count as real text.
     */
    private const MIN_AVERAGE_CHARS_PER_PAGE = 10;

    /** Poppler exit codes (see `man pdftotext`). */
    private const EXIT_OPEN_ERROR = 1;

    private const EXIT_PERMISSION_ERROR = 3;

    public function __construct(
        private readonly string $pdftotextBinary,
        private readonly string $pdfinfoBinary,
        private readonly int $timeoutSeconds,
    ) {}

    public function inspect(string $path): PdfInfo
    {
        $result = $this->run([$this->pdfinfoBinary, $path]);

        if (! preg_match('/^Pages:\s+(\d+)\s*$/m', $result->output(), $matches)) {
            throw PdfExtractionException::invalidPdf('pdfinfo output has no page count');
        }

        return new PdfInfo(pages: (int) $matches[1]);
    }

    public function extractPages(string $path): array
    {
        // "-" writes to stdout; UTF-8 and \n line endings regardless of platform.
        $result = $this->run([$this->pdftotextBinary, '-enc', 'UTF-8', '-eol', 'unix', $path, '-']);

        $pages = explode("\f", $result->output());

        // A form feed follows every page, so the element after the last one is not a page
        // (normally empty; tolerate a trailing newline). An empty last page is kept, because
        // it produces its own element before that final form feed.
        if ($pages !== [] && trim(end($pages)) === '') {
            array_pop($pages);
        }

        $pages = array_map($this->clean(...), $pages);

        if ($pages === [] || $this->visibleCharacters($pages) / count($pages) < self::MIN_AVERAGE_CHARS_PER_PAGE) {
            throw PdfExtractionException::noTextLayer();
        }

        return $pages;
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): ProcessResult
    {
        try {
            $result = Process::timeout($this->timeoutSeconds)->run($command);
        } catch (ProcessTimedOutException) {
            throw PdfExtractionException::timedOut();
        }

        if ($result->successful()) {
            return $result;
        }

        $error = trim($result->errorOutput());

        throw match ($result->exitCode()) {
            self::EXIT_OPEN_ERROR => str_contains(strtolower($error), 'password')
                ? PdfExtractionException::encrypted()
                : PdfExtractionException::invalidPdf($error),
            self::EXIT_PERMISSION_ERROR => PdfExtractionException::notPermitted(),
            default => PdfExtractionException::failed("exit code {$result->exitCode()}: {$error}"),
        };
    }

    /**
     * Normalises extracted text so it is safe to store and pleasant to read:
     * valid UTF-8, no NUL bytes (PostgreSQL rejects them in text columns), no
     * control characters, and collapsed whitespace.
     */
    private function clean(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Remove control characters except tab and newline (this includes NUL).
        $text = preg_replace('/[^\P{Cc}\t\n]/u', '', $text) ?? '';
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? '';
        $text = preg_replace('/ *\n */', "\n", $text) ?? '';
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';

        return trim($text);
    }

    /**
     * @param  list<string>  $pages
     */
    private function visibleCharacters(array $pages): int
    {
        return array_sum(array_map(
            fn (string $page): int => mb_strlen(preg_replace('/\s+/u', '', $page) ?? ''),
            $pages,
        ));
    }
}
