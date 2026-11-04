<?php

namespace Tests\Support;

use App\Services\Pdf\PdfExtractionException;
use App\Services\Pdf\PdfInfo;
use App\Services\Pdf\PdfTextExtractor;
use Closure;

/**
 * Stands in for Poppler. extractPages() returns the given pages, throws the
 * given exception, or runs the given callback (to simulate side effects).
 */
final class FakePdfTextExtractor implements PdfTextExtractor
{
    public int $extractCalls = 0;

    /**
     * @param  list<string>|PdfExtractionException|Closure(string): list<string>  $pages
     */
    public function __construct(private array|PdfExtractionException|Closure $pages = ['Fake page text.']) {}

    public function inspect(string $path): PdfInfo
    {
        return new PdfInfo(is_array($this->pages) ? count($this->pages) : 1);
    }

    public function extractPages(string $path): array
    {
        $this->extractCalls++;

        return match (true) {
            $this->pages instanceof PdfExtractionException => throw $this->pages,
            $this->pages instanceof Closure => ($this->pages)($path),
            default => $this->pages,
        };
    }

    /** Binds a new fake into the container and returns it for assertions. */
    public static function bind(array|PdfExtractionException|Closure $pages = ['Fake page text.']): self
    {
        $fake = new self($pages);
        app()->instance(PdfTextExtractor::class, $fake);

        return $fake;
    }
}
