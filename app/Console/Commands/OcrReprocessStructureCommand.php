<?php

namespace App\Console\Commands;

use App\Models\OcrDocument;
use App\Services\Ocr\OcrStructurePersistService;
use Illuminate\Console\Command;

class OcrReprocessStructureCommand extends Command
{
    protected $signature = 'ocr:reprocess-structure {document : OCR document ID}';

    protected $description = 'Safely replace OCR parsed firms from stored Document AI layout (new parse run)';

    public function handle(OcrStructurePersistService $persist): int
    {
        $id = (int) $this->argument('document');
        $document = OcrDocument::query()->find($id);
        if ($document === null) {
            $this->error("OCR document #{$id} not found.");

            return self::FAILURE;
        }
        if (! $document->isCompleted() && trim((string) ($document->extracted_text ?? '')) === '') {
            $this->error('Document has no completed OCR text/layout to re-structure.');

            return self::FAILURE;
        }

        $this->info("Re-structuring #{$id} ({$document->original_filename})…");
        $out = $persist->parseAndPersist($document->fresh());
        $this->info('Done. firms='.(int) $out->parsed_firm_count.' profile='.($out->structured_data['directory_profile'] ?? '-'));

        return self::SUCCESS;
    }
}
