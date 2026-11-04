<?php

namespace App\Services\Pdf;

use RuntimeException;

/**
 * Why a PDF could not be read. The message is safe to show to users; `code`
 * values are stored as documents.error_code and are part of the API contract.
 *
 * Permanent failures (bad file) must not be retried; transient ones (timeout,
 * unexpected tool failure) may succeed on a later attempt.
 */
final class PdfExtractionException extends RuntimeException
{
    public const INVALID_PDF = 'invalid_pdf';

    public const ENCRYPTED_PDF = 'encrypted_pdf';

    public const EXTRACTION_NOT_PERMITTED = 'extraction_not_permitted';

    public const NO_TEXT_LAYER = 'no_text_layer';

    public const EXTRACTION_TIMEOUT = 'extraction_timeout';

    public const EXTRACTION_FAILED = 'extraction_failed';

    private function __construct(
        public readonly string $reason,
        string $message,
        private readonly bool $permanent,
        /** Technical detail for logs only (tool output). Never shown to users. */
        public readonly ?string $detail = null,
    ) {
        parent::__construct($message);
    }

    public static function invalidPdf(?string $detail = null): self
    {
        return new self(self::INVALID_PDF, 'This file is not a valid PDF or is damaged.', true, $detail);
    }

    public static function encrypted(): self
    {
        return new self(self::ENCRYPTED_PDF, 'This PDF is password-protected. Remove the password and upload it again.', true);
    }

    public static function notPermitted(): self
    {
        return new self(self::EXTRACTION_NOT_PERMITTED, 'This PDF does not allow its text to be copied.', true);
    }

    public static function noTextLayer(): self
    {
        return new self(
            self::NO_TEXT_LAYER,
            'This PDF contains no selectable text (it may be a scanned document). Scanned PDFs are not supported yet.',
            true,
        );
    }

    public static function timedOut(): self
    {
        return new self(self::EXTRACTION_TIMEOUT, 'Reading this PDF took too long.', false);
    }

    public static function failed(?string $detail = null): self
    {
        return new self(self::EXTRACTION_FAILED, 'This PDF could not be processed.', false, $detail);
    }

    public function isPermanent(): bool
    {
        return $this->permanent;
    }
}
