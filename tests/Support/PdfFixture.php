<?php

namespace Tests\Support;

/**
 * Builds small, valid PDF files for tests, so no binary fixtures need to be
 * committed. Each array item is one page; an empty string produces a page
 * without any text (like a scanned page). Text must be ASCII; use "\n" for lines.
 */
final class PdfFixture
{
    /**
     * @param  list<string>  $pages
     */
    public static function bytes(array $pages): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $kids = [];
        $nextId = 4;

        foreach ($pages as $text) {
            $pageId = $nextId++;
            $contentId = $nextId++;
            $stream = self::contentStream($text);

            $kids[] = "{$pageId} 0 R";
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($pages).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $size = count($objects) + 1;
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }

    /**
     * Writes a PDF to a temporary file and returns its path. Delete it after use.
     *
     * @param  list<string>  $pages
     */
    public static function file(array $pages): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf-fixture-');
        file_put_contents($path, self::bytes($pages));

        return $path;
    }

    private static function contentStream(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }

        $lines = array_map(
            fn (string $line): string => '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line).') Tj T*',
            explode("\n", $text),
        );

        return "BT /F1 12 Tf 14 TL 72 720 Td\n".implode("\n", $lines)."\nET";
    }
}
