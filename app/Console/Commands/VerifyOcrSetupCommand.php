<?php

namespace App\Console\Commands;

use App\Services\DocumentAi\GoogleDocumentAiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class VerifyOcrSetupCommand extends Command
{
    protected $signature = 'ocr:verify';

    protected $description = 'Verify Google Document AI OCR configuration and database readiness';

    public function handle(GoogleDocumentAiService $documentAiService): int
    {
        $checks = [];

        $checks[] = $this->check(
            'Composer package installed',
            class_exists(\Google\Cloud\DocumentAI\V1\Client\DocumentProcessorServiceClient::class),
        );

        $checks[] = $this->check(
            'Cloud Storage package installed',
            class_exists(\Google\Cloud\Storage\StorageClient::class),
        );

        $checks[] = $this->check(
            'PDF page counter package installed',
            class_exists(\Smalot\PdfParser\Parser::class),
        );

        $checks[] = $this->check(
            'ocr_documents table exists',
            Schema::hasTable('ocr_documents'),
        );

        $checks[] = $this->check(
            'batch OCR columns exist',
            Schema::hasColumn('ocr_documents', 'processing_mode')
                && Schema::hasColumn('ocr_documents', 'provider_operation_name'),
        );

        $checks[] = $this->check(
            'Project ID configured',
            (string) config('document-ai.project_id') !== '',
        );

        $checks[] = $this->check(
            'Processor ID configured',
            (string) config('document-ai.processor_id') !== '',
        );

        $location = (string) config('document-ai.location', '');
        $checks[] = $this->check(
            'Processor location valid',
            in_array($location, config('document-ai.allowed_locations', []), true),
            'Current: '.$location,
        );

        $credentialsPath = $documentAiService->resolveCredentialsPath();
        $expectedRelative = 'storage/app/google/document-ai-service-account.json';
        if ($credentialsPath !== null) {
            $checks[] = $this->check('Credentials file readable', true, $expectedRelative);
        } else {
            $checks[] = $this->check(
                'Credentials file readable',
                false,
                'Missing '.$expectedRelative.' (or path in GOOGLE_DOCUMENT_AI_CREDENTIALS / GOOGLE_APPLICATION_CREDENTIALS)',
            );
        }

        try {
            $documentAiService->validateConfiguration();
            $checks[] = $this->check('Full Document AI configuration', true);
        } catch (\Throwable $exception) {
            $checks[] = $this->check('Full Document AI configuration', false, $exception->getMessage());
        }

        $inputBucket = trim((string) config('document-ai.gcs.input_bucket', ''));
        $outputBucket = trim((string) config('document-ai.gcs.output_bucket', ''));
        $batchReady = $inputBucket !== '' && $outputBucket !== '';
        $checks[] = $this->check(
            'Batch OCR Cloud Storage buckets',
            $batchReady,
            $batchReady
                ? 'input+output configured'
                : 'Set private bucket names in .env (GOOGLE_CLOUD_STORAGE_INPUT_BUCKET / OUTPUT_BUCKET, names only, no gs://)',
        );

        $checks[] = $this->check(
            'Small-file sync fast path',
            true,
            filter_var(config('document-ai.sync_small_files', true), FILTER_VALIDATE_BOOLEAN)
                ? 'Enabled (≤'.config('document-ai.sync_max_pages', 5).' pages / ≤'.config('document-ai.sync_max_file_mb', 5).' MB)'
                : 'Disabled — online OCR waits for queue worker',
        );

        $queue = (string) config('queue.default');
        $checks[] = $this->check(
            'Queue connection',
            true,
            'Using: '.$queue.($queue === 'sync'
                ? ' (jobs run immediately)'
                : ' (cron: php artisan queue:work --stop-when-empty --tries=3 --timeout=120; auto_drain='.json_encode((bool) config('crm_queue.auto_drain')).')'),
        );

        $checks[] = $this->check(
            'Online page limit',
            true,
            (string) config('document-ai.online_max_pages', 30).' pages',
        );
        $checks[] = $this->check(
            'Batch page limit',
            true,
            (string) config('document-ai.batch_max_pages', 500).' pages',
        );

        $this->newLine();
        $failed = collect($checks)->where('ok', false)->count();

        if ($failed === 0) {
            $this->info('OCR is ready for online and batch document processing.');

            return self::SUCCESS;
        }

        $this->warn($failed.' check(s) failed. Fix the items above, then run: php artisan ocr:verify');
        $this->line('Hostinger tip: every minute cron → php artisan schedule:run and/or php artisan queue:work --stop-when-empty --tries=3 --timeout=300');

        return self::FAILURE;
    }

    private function check(string $label, bool $ok, ?string $detail = null): array
    {
        if ($ok) {
            $this->line('<fg=green>✓</> '.$label.($detail ? ' — '.$detail : ''));
        } else {
            $this->line('<fg=red>✗</> '.$label.($detail ? ' — '.$detail : ''));
        }

        return ['label' => $label, 'ok' => $ok, 'detail' => $detail];
    }
}
