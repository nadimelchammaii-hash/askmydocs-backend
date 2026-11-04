<?php

namespace App\Services\Pdf;

/**
 * Reads PDFs without trusting them: everything returned is untrusted document
 * content and must never be treated as instructions.
 */
interface PdfTextExtractor
{
    /**
     * Read basic properties (page count) without extracting text.
     *
     * @throws PdfExtractionException
     */
    public function inspect(string $path): PdfInfo;

    /**
     * Extract cleaned text per page. Index 0 is page 1; pages without text are empty strings.
     *
     * @return list<string>
     *
     * @throws PdfExtractionException when the PDF cannot be read or contains no extractable text
     */
    public function extractPages(string $path): array;
}
