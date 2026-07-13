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
            'ocr_documents table exists',
            Schema::hasTable('ocr_documents'),
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
        if ($credentialsPath !== null) {
            $checks[] = $this->check('Credentials file readable', true, $credentialsPath);
        } elseif ($documentAiService->usesApplicationDefaultCredentials()) {
            $checks[] = $this->check(
                'Credentials',
                true,
                'Using Google Application Default Credentials (no local JSON path configured)',
            );
        } else {
            $checks[] = $this->check(
                'Credentials',
                false,
                'Set GOOGLE_APPLICATION_CREDENTIALS or place JSON at storage/app/google/document-ai-service-account.json',
            );
        }

        try {
            $documentAiService->validateConfiguration();
            $checks[] = $this->check('Full Document AI configuration', true);
        } catch (\Throwable $exception) {
            $checks[] = $this->check('Full Document AI configuration', false, $exception->getMessage());
        }

        $checks[] = $this->check(
            'Queue connection',
            true,
            'Using: '.config('queue.default').(config('queue.default') === 'sync' ? ' (jobs run immediately)' : ' (run: php artisan queue:work)'),
        );

        $this->newLine();
        $failed = collect($checks)->where('ok', false)->count();

        if ($failed === 0) {
            $this->info('OCR is ready for live document processing.');

            return self::SUCCESS;
        }

        $this->warn($failed.' check(s) failed. Fix the items above, then run: php artisan ocr:verify');

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
