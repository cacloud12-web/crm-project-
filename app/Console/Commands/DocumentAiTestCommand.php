<?php

namespace App\Console\Commands;

use App\Contracts\Ocr\OcrProcessorInterface;
use App\Exceptions\DocumentAi\DocumentAiConfigurationException;
use App\Exceptions\Ocr\OcrProviderException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class DocumentAiTestCommand extends Command
{
    protected $signature = 'document-ai:test {file? : Absolute or relative path to a PDF/JPG/PNG file}';

    protected $description = 'Validate Document AI config and send one local document (short text preview only; never prints credentials)';

    public function handle(OcrProcessorInterface $processor): int
    {
        try {
            $processor->validateConfiguration();
        } catch (DocumentAiConfigurationException $e) {
            $this->error($e->getMessage());
            $this->line('Your .env project/processor/location values are read from environment — only the service-account JSON file is still required on disk.');

            return self::FAILURE;
        }

        $path = $this->resolveInputFile();
        if ($path === null) {
            $this->error('Provide a readable PDF/JPG/PNG path, e.g. php artisan document-ai:test /path/to/sample.pdf');

            return self::FAILURE;
        }

        $size = filesize($path) ?: 0;
        $maxBytes = max(1, (int) config('document-ai.max_file_mb', 20)) * 1024 * 1024;
        if ($size <= 0) {
            $this->error('The uploaded document is empty.');

            return self::FAILURE;
        }
        if ($size > $maxBytes) {
            $this->error('File exceeds configured GOOGLE_DOCUMENT_AI_MAX_FILE_MB limit.');

            return self::FAILURE;
        }

        $mime = File::mimeType($path) ?: 'application/octet-stream';
        $allowed = config('document-ai.supported_mime_types', []);
        if (! in_array($mime, $allowed, true)) {
            $this->error('Unsupported MIME type: '.$mime);

            return self::FAILURE;
        }

        $this->info('Configuration OK. Sending document to Google Document AI…');

        try {
            $binary = File::get($path);
            $result = $processor->processBinary($binary, $mime);
        } catch (DocumentAiConfigurationException|OcrProviderException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('The document could not be processed. Please verify the OCR configuration or retry.');

            return self::FAILURE;
        }

        $text = (string) ($result['text'] ?? '');
        $preview = mb_substr($text, 0, 400);
        if (mb_strlen($text) > 400) {
            $preview .= '…';
        }

        $this->info('Document AI OCR succeeded.');
        $this->line('Pages: '.($result['page_count'] ?? 'n/a'));
        $langs = $result['languages'] ?? $result['detected_languages'] ?? [];
        $this->line('Languages: '.($langs !== [] ? implode(', ', $langs) : 'n/a'));
        $this->newLine();
        $this->line('Preview:');
        $this->line($preview !== '' ? $preview : '(empty text)');

        return self::SUCCESS;
    }

    private function resolveInputFile(): ?string
    {
        $path = (string) ($this->argument('file') ?? '');
        if ($path === '') {
            $bundled = storage_path('app/google/_sample_ocr_test.pdf');
            if (is_readable($bundled) && is_file($bundled) && (filesize($bundled) ?: 0) > 0) {
                $this->line('No file argument given — using bundled sample: storage/app/google/_sample_ocr_test.pdf');

                return $bundled;
            }

            return null;
        }

        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = base_path($path);
        }

        if (! is_readable($path) || ! is_file($path)) {
            $this->error('File not found or not readable.');

            return null;
        }

        return $path;
    }
}
