<?php

namespace App\Services\Ocr;

use App\Exceptions\DocumentAi\DocumentAiConfigurationException;
use App\Exceptions\Ocr\OcrFileException;
use App\Models\OcrDocument;
use Illuminate\Support\Facades\Log;

class OcrProcessingModeSelector
{
    public const MODE_ONLINE = 'online';

    public const MODE_BATCH = 'batch';

    public const USER_BATCH_CONFIG_MESSAGE = 'Large-document processing is not configured. Please contact the administrator.';

    public function __construct(
        private readonly PdfPageCounter $pageCounter,
    ) {}

    /**
     * @return array{mode: string, page_count: int|null, reason: string}
     */
    public function decide(string $mimeType, int $fileSizeBytes, string $binary = ''): array
    {
        $onlineMaxPages = max(1, (int) config('document-ai.online_max_pages', 30));
        $batchMaxPages = max($onlineMaxPages, (int) config('document-ai.batch_max_pages', 500));
        $onlineMaxBytes = max(1, (int) config('document-ai.online_max_file_mb', 40)) * 1024 * 1024;
        $batchMaxBytes = max(1, (int) config('document-ai.batch_max_file_mb', 1024)) * 1024 * 1024;

        if ($fileSizeBytes > $batchMaxBytes) {
            throw new OcrFileException(
                'This document exceeds the maximum supported OCR file size ('
                .config('document-ai.batch_max_file_mb', 1024).' MB).',
                'file_too_large',
            );
        }

        $isPdf = str_contains(strtolower($mimeType), 'pdf');
        $pageCount = null;

        if ($isPdf && $binary !== '') {
            $pageCount = $this->pageCounter->count($binary);
        }

        if (! $isPdf) {
            return [
                'mode' => self::MODE_ONLINE,
                'page_count' => 1,
                'reason' => 'image_online',
            ];
        }

        if ($pageCount !== null && $pageCount > $batchMaxPages) {
            throw new OcrFileException(
                "This PDF has {$pageCount} pages, which exceeds the batch OCR limit of {$batchMaxPages} pages. "
                .'Please contact the administrator for large-document support beyond this limit.',
                'batch_page_limit_exceeded',
            );
        }

        if ($pageCount !== null && $pageCount > $onlineMaxPages) {
            return [
                'mode' => self::MODE_BATCH,
                'page_count' => $pageCount,
                'reason' => 'page_count_exceeds_online',
            ];
        }

        if ($fileSizeBytes > $onlineMaxBytes) {
            return [
                'mode' => self::MODE_BATCH,
                'page_count' => $pageCount,
                'reason' => 'file_size_exceeds_online',
            ];
        }

        if ($pageCount === null && $fileSizeBytes > (2 * 1024 * 1024)) {
            return [
                'mode' => self::MODE_BATCH,
                'page_count' => null,
                'reason' => 'unknown_pages_prefer_batch',
            ];
        }

        return [
            'mode' => self::MODE_ONLINE,
            'page_count' => $pageCount,
            'reason' => 'within_online_limits',
        ];
    }

    /**
     * Validate batch OCR prerequisites. Throws a user-friendly exception; admin detail is logged.
     */
    public function assertBatchConfigured(): void
    {
        $input = $this->normalizedBucket((string) config('document-ai.gcs.input_bucket', ''));
        $output = $this->normalizedBucket((string) config('document-ai.gcs.output_bucket', ''));

        if ($input === '' && $output === '') {
            $this->throwBatchConfigError(
                'Both Cloud Storage input and output bucket names are empty. Set GOOGLE_CLOUD_STORAGE_INPUT_BUCKET and GOOGLE_CLOUD_STORAGE_OUTPUT_BUCKET (bucket names only, no gs://).',
            );
        }

        if ($input === '') {
            $this->throwBatchConfigError(
                'Cloud Storage input bucket is empty. Set GOOGLE_CLOUD_STORAGE_INPUT_BUCKET to the private input bucket name (no gs:// prefix).',
            );
        }

        if ($output === '') {
            $this->throwBatchConfigError(
                'Cloud Storage output bucket is empty. Set GOOGLE_CLOUD_STORAGE_OUTPUT_BUCKET to the private output bucket name (no gs:// prefix).',
            );
        }

        if (! $this->isValidBucketName($input)) {
            $this->throwBatchConfigError(
                'Cloud Storage input bucket name is invalid. Use a valid GCS bucket name without gs://.',
            );
        }

        if (! $this->isValidBucketName($output)) {
            $this->throwBatchConfigError(
                'Cloud Storage output bucket name is invalid. Use a valid GCS bucket name without gs://.',
            );
        }

        if ($input === $output) {
            $this->throwBatchConfigError(
                'Cloud Storage input and output buckets must be different private buckets.',
            );
        }
    }

    public function requiresBatch(OcrDocument $document): bool
    {
        return $document->processing_mode === self::MODE_BATCH;
    }

    public function normalizedBucket(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('#^gs://#i', '', $value) ?? $value;
        $value = trim($value, '/');

        // If someone pasted gs://bucket/path, keep only the bucket segment.
        if (str_contains($value, '/')) {
            $value = explode('/', $value, 2)[0];
        }

        return trim($value);
    }

    private function isValidBucketName(string $name): bool
    {
        // GCS bucket naming rules (simplified): 3–63 chars, lowercase letters/numbers/dashes/dots/underscores.
        return (bool) preg_match('/^[a-z0-9][a-z0-9._-]{1,61}[a-z0-9]$/', $name);
    }

    private function throwBatchConfigError(string $adminDetail): void
    {
        Log::warning('ocr.batch.configuration_incomplete', [
            'detail' => $adminDetail,
        ]);

        throw new DocumentAiConfigurationException(
            self::USER_BATCH_CONFIG_MESSAGE,
            $adminDetail,
        );
    }
}
