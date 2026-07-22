<?php

namespace App\Console\Commands;

use App\Models\OcrDocument;
use App\Services\Ocr\MasterCaDirectImportService;
use Illuminate\Console\Command;
use Throwable;

class OcrApproveAllEligibleCommand extends Command
{
    protected $signature = 'ocr:approve-all-eligible
        {document? : OCR document id}
        {--all : Process every Master CA OCR document with eligible rows}
        {--actor= : Optional actor user id for audit}
        {--force : Required in production}';

    protected $description = 'Accept all eligible (verified/matched) Master CA OCR staging rows into ca_masters';

    public function handle(MasterCaDirectImportService $importer): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing production bulk accept without --force.');

            return self::FAILURE;
        }

        if (! (bool) config('ocr_safety.allow_bulk_approve_safe', false)
            && (bool) config('ocr_safety.require_verification', true)) {
            $this->error('OCR_ALLOW_BULK_APPROVE_SAFE is false. Set it to true in .env, then config:clear.');

            return self::FAILURE;
        }

        $actorId = $this->option('actor') !== null ? (int) $this->option('actor') : null;
        $documentId = $this->argument('document');
        $all = (bool) $this->option('all');

        if (! $all && ! $documentId) {
            $this->error('Pass a document id or --all.');

            return self::FAILURE;
        }

        $query = OcrDocument::query()->where('import_type', OcrDocument::IMPORT_MASTER_CA);
        if (! $all) {
            $query->whereKey((int) $documentId);
        }

        $documents = $query->orderBy('id')->get();
        if ($documents->isEmpty()) {
            $this->warn('No Master CA OCR documents found.');

            return self::SUCCESS;
        }

        foreach ($documents as $document) {
            $this->info('Document #'.$document->id.' — '.$document->original_filename);
            try {
                $stats = $importer->approveAllEligible($document, $actorId);
                $this->table(
                    ['Metric', 'Count'],
                    collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->all()
                );
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
