<?php

namespace App\Console\Commands;

use App\Models\OcrDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class OcrDebugDocumentCommand extends Command
{
    protected $signature = 'ocr:debug-document {documentId : OCR document ID}';

    protected $description = 'Inspect one OCR document row, stored file, queue jobs, and failures';

    public function handle(): int
    {
        $id = (int) $this->argument('documentId');
        $document = OcrDocument::withTrashed()->find($id);
        if (! $document) {
            $this->error('Document #'.$id.' not found (including soft-deleted).');

            return self::FAILURE;
        }

        $fileExists = false;
        try {
            $fileExists = Storage::disk((string) $document->storage_disk)->exists((string) $document->storage_path);
        } catch (\Throwable) {
            $fileExists = false;
        }

        $this->info('OCR document #'.$document->id);
        $this->line('original_filename='.$document->original_filename);
        $this->line('status='.$document->status);
        $this->line('pipeline_stage='.$document->pipelineStage());
        $this->line('processing_progress='.(string) $document->processing_progress);
        $this->line('parse_status='.(string) $document->parse_status);
        $this->line('import_type='.(string) $document->import_type);
        $this->line('processing_mode='.(string) $document->processing_mode);
        $this->line('uploaded_by='.(string) $document->uploaded_by);
        $this->line('page_count='.(string) $document->page_count);
        $this->line('file_size='.(string) $document->file_size);
        $this->line('checksum='.(string) $document->checksum);
        $this->line('storage_disk='.(string) $document->storage_disk);
        $this->line('storage_path='.(string) $document->storage_path);
        $this->line('stored_file_exists='.($fileExists ? 'yes' : 'no'));
        $this->line('error_code='.(string) $document->error_code);
        $this->line('error_message='.mb_substr((string) $document->error_message, 0, 300));
        $this->line('created_at='.optional($document->created_at)?->toIso8601String());
        $this->line('updated_at='.optional($document->updated_at)?->toIso8601String());
        $this->line('deleted_at='.optional($document->deleted_at)?->toIso8601String());
        $this->line('processing_started_at='.optional($document->processing_started_at)?->toIso8601String());
        $this->line('processed_at='.optional($document->processed_at)?->toIso8601String());
        $this->line('failed_at='.optional($document->failed_at)?->toIso8601String());

        if (Schema::hasTable('jobs')) {
            $jobs = DB::table('jobs')->orderByDesc('id')->limit(50)->get();
            $matched = 0;
            foreach ($jobs as $job) {
                $payload = json_decode((string) $job->payload, true) ?: [];
                $command = (string) ($payload['data']['command'] ?? '');
                if (! str_contains($command, 'ocrDocumentId";i:'.$id.';') && ! str_contains($command, '"ocrDocumentId";i:'.$id.';') && ! str_contains((string) ($payload['displayName'] ?? ''), 'Ocr') ) {
                    // Still try display payload string match for document id.
                    if (! str_contains((string) $job->payload, ';i:'.$id.';') && ! str_contains((string) $job->payload, ':'.$id.';')) {
                        continue;
                    }
                }
                if (! str_contains((string) $job->payload, (string) $id)) {
                    continue;
                }
                $matched++;
                $this->line(sprintf(
                    'job id=%s queue=%s attempts=%s reserved_at=%s available_at=%s display=%s',
                    $job->id,
                    $job->queue,
                    $job->attempts,
                    $job->reserved_at ?? 'null',
                    $job->available_at,
                    $payload['displayName'] ?? 'unknown',
                ));
            }
            if ($matched === 0) {
                $this->line('queue_jobs_for_document=none');
            }
        }

        if (Schema::hasTable('failed_jobs')) {
            $failed = DB::table('failed_jobs')->orderByDesc('id')->limit(20)->get();
            $found = 0;
            foreach ($failed as $row) {
                if (! str_contains((string) $row->payload, (string) $id)) {
                    continue;
                }
                $found++;
                $this->line('failed_job id='.$row->id.' queue='.$row->queue.' failed_at='.$row->failed_at);
                $this->line('failed_exception='.mb_substr((string) $row->exception, 0, 240));
            }
            if ($found === 0) {
                $this->line('failed_jobs_for_document=none');
            }
        }

        return self::SUCCESS;
    }
}
