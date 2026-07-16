<?php

namespace App\Services\Ocr;

use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Models\OcrParsedMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the structure parser and persists firms/members in memory-safe batches.
 */
class OcrStructurePersistService
{
    private const FIRM_INSERT_CHUNK = 100;

    public function __construct(
        private readonly OcrStructureParserService $parser,
    ) {}

    public function parseAndPersist(OcrDocument $document): OcrDocument
    {
        $text = (string) ($document->displayText() ?? '');
        $document->update([
            'parse_status' => 'processing',
            'processing_progress' => 'Structuring OCR results',
        ]);

        try {
            $parsed = $this->parser->parse($text);
            $this->replaceParsedRecords($document, $parsed['firms']);

            $structured = is_array($document->structured_data) ? $document->structured_data : [];
            $structured['parsed'] = [
                'parser_version' => $parsed['parser_version'],
                'firm_count' => $parsed['firm_count'],
                'parsed_at' => now()->toIso8601String(),
            ];

            $document->update([
                'parse_status' => 'completed',
                'parsed_firm_count' => $parsed['firm_count'],
                'parsed_at' => now(),
                'structured_data' => $structured,
                'processing_progress' => 'Completed',
            ]);

            Log::info('ocr.document.structured', [
                'ocr_document_id' => $document->id,
                'firm_count' => $parsed['firm_count'],
                'parser_version' => $parsed['parser_version'],
            ]);
        } catch (Throwable $exception) {
            Log::warning('ocr.document.structure_failed', [
                'ocr_document_id' => $document->id,
                'error' => class_basename($exception),
            ]);

            $document->update([
                'parse_status' => 'failed',
                'processing_progress' => 'Completed',
            ]);

            throw $exception;
        }

        return $document->fresh(['parsedFirms.members']);
    }

    /**
     * @param  list<array<string, mixed>>  $firms
     */
    private function replaceParsedRecords(OcrDocument $document, array $firms): void
    {
        // Short DB transaction for delete + inserts only (no OCR / cloud I/O).
        DB::transaction(function () use ($document, $firms) {
            OcrParsedMember::query()
                ->whereIn('ocr_parsed_firm_id', OcrParsedFirm::query()
                    ->where('ocr_document_id', $document->id)
                    ->select('id'))
                ->delete();

            OcrParsedFirm::query()->where('ocr_document_id', $document->id)->delete();

            foreach (array_chunk($firms, self::FIRM_INSERT_CHUNK) as $chunk) {
                foreach ($chunk as $firmData) {
                    $members = $firmData['members'] ?? [];
                    unset($firmData['members']);

                    $firm = OcrParsedFirm::query()->create([
                        'ocr_document_id' => $document->id,
                        'sequence_no' => (int) ($firmData['sequence_no'] ?? 1),
                        'firm_name' => $firmData['firm_name'] ?? null,
                        'firm_type' => $firmData['firm_type'] ?? null,
                        'frn' => $firmData['frn'] ?? null,
                        'gst_no' => $firmData['gst_no'] ?? null,
                        'pan_no' => $firmData['pan_no'] ?? null,
                        'address' => $firmData['address'] ?? null,
                        'city' => $firmData['city'] ?? null,
                        'state' => $firmData['state'] ?? null,
                        'pincode' => $firmData['pincode'] ?? null,
                        'phone' => $firmData['phone'] ?? null,
                        'email' => $firmData['email'] ?? null,
                        'website' => $firmData['website'] ?? null,
                        'review_status' => $firmData['review_status'] ?? OcrParsedFirm::REVIEW_PENDING,
                        'overall_confidence' => $firmData['overall_confidence'] ?? null,
                        'page_number' => $firmData['page_number'] ?? null,
                        'field_meta' => $firmData['field_meta'] ?? null,
                    ]);

                    $memberRows = [];
                    foreach ($members as $member) {
                        $memberRows[] = [
                            'ocr_parsed_firm_id' => $firm->id,
                            'sequence_no' => (int) ($member['sequence_no'] ?? 1),
                            'ca_name' => $member['ca_name'] ?? null,
                            'membership_no' => $member['membership_no'] ?? null,
                            'mobile' => $member['mobile'] ?? null,
                            'email' => $member['email'] ?? null,
                            'role' => $member['role'] ?? null,
                            'overall_confidence' => $member['overall_confidence'] ?? null,
                            'field_meta' => isset($member['field_meta']) ? json_encode($member['field_meta']) : null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    if ($memberRows !== []) {
                        OcrParsedMember::query()->insert($memberRows);
                    }
                }
            }
        });
    }
}
