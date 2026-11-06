<?php

namespace App\Http\Requests\Documents;

use App\Exceptions\ApiException;
use App\Services\Pdf\PdfExtractionException;
use App\Services\Pdf\PdfTextExtractor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;
use LogicException;

/**
 * Validates an uploaded PDF in increasing order of cost:
 *
 *  1. extension, size and content type (PHP's fileinfo reads the file's magic
 *     bytes, so a renamed .exe is rejected even though its name ends in .pdf);
 *  2. only if those pass: pdfinfo opens the file, which rejects corrupt and
 *     password-protected PDFs and gives the page count to check the limit.
 */
class StoreDocumentRequest extends FormRequest
{
    private ?int $pageCount = null;

    /**
     * Demo accounts may upload only a few PDFs of their own (config demo.max_uploads).
     * Checked before validation, so a refused upload never reaches pdfinfo.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $max = (int) config('demo.max_uploads');

        if ($user?->isDemo() && $user->documents()->where('is_sample', false)->count() >= $max) {
            throw new ApiException(403, 'demo_upload_limit', "Demo accounts can upload {$max} ".Str::plural('PDF', $max).' of their own. Create your own account to upload more.');
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'extensions:pdf',
                'mimetypes:application/pdf',
                'max:'.config('documents.max_size_kb'),
            ],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $limit = self::formatSize((int) config('documents.max_size_kb'));

        return [
            'file.extensions' => 'The file must be a PDF.',
            'file.mimetypes' => 'The file must be a PDF.',
            'file.max' => "The file may not be larger than {$limit}.",
            // PHP itself discards files above upload_max_filesize; Laravel then reports "failed to upload".
            'file.uploaded' => "The file could not be uploaded. PDFs may be at most {$limit}.",
        ];
    }

    private static function formatSize(int $kilobytes): string
    {
        return $kilobytes >= 1024 && $kilobytes % 1024 === 0 ? ($kilobytes / 1024).' MB' : "{$kilobytes} KB";
    }

    /**
     * @return array<int, callable>
     */
    public function after(PdfTextExtractor $extractor): array
    {
        return [
            function (Validator $validator) use ($extractor): void {
                if ($validator->errors()->isNotEmpty()) {
                    return; // Never run an external tool on a file that already failed the cheap checks.
                }

                /** @var UploadedFile $file */
                $file = $this->file('file');

                try {
                    $pages = $extractor->inspect($file->getRealPath())->pages;
                } catch (PdfExtractionException $exception) {
                    // Reason and tool output only; never the document's content.
                    Log::info('PDF upload rejected', ['reason' => $exception->reason, 'detail' => $exception->detail]);
                    $validator->errors()->add('file', $exception->getMessage());

                    return;
                }

                $max = (int) config('documents.max_pages');

                if ($pages < 1) {
                    $validator->errors()->add('file', 'This PDF has no pages.');
                } elseif ($pages > $max) {
                    $validator->errors()->add('file', "This PDF has {$pages} pages; the maximum is {$max}.");
                } else {
                    $this->pageCount = $pages;
                }
            },
        ];
    }

    /** Page count read by pdfinfo during validation. */
    public function pageCount(): int
    {
        return $this->pageCount ?? throw new LogicException('pageCount() is only available after successful validation.');
    }
}
