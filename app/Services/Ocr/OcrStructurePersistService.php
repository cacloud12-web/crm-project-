<?php

namespace App\Services\Ocr;

use App\Jobs\MapOcrParsedFirmsJob;
use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Models\OcrParsedMember;
use App\Services\Mapping\DataNormalizationService;
use App\Services\Mapping\MasterDataMappingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the structure parser and persists firms/members in memory-safe batches.
 * Staging only for extraction — Master mapping is delegated to MasterDataMappingService.
 */
class OcrStructurePersistService
{
    private const FIRM_INSERT_CHUNK = 100;

    public function __construct(
        private readonly OcrStructureParserService $parser,
        private readonly DataNormalizationService $normalizer,
        private readonly MasterDataMappingService $mappingService,
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
            $completeness = $this->evaluateCompleteness($document, $parsed, $text);
            $this->replaceParsedRecords($document, $parsed['firms']);

            $structured = is_array($document->structured_data) ? $document->structured_data : [];
            $structured['parsed'] = [
                'parser_version' => $parsed['parser_version'],
                'firm_count' => $parsed['firm_count'],
                'heading_count' => $parsed['heading_count'] ?? null,
                'skipped_blocks' => $parsed['skipped_blocks'] ?? 0,
                'parsed_at' => now()->toIso8601String(),
                'completeness' => $completeness,
            ];

            $document->update([
                'parse_status' => 'completed',
                'parsed_firm_count' => $parsed['firm_count'],
                'parsed_at' => now(),
                'structured_data' => $structured,
                'processing_progress' => ! empty($completeness['needs_review'])
                    ? 'Completed with extraction warnings — mapping next'
                    : 'Mapping to Master Data',
            ]);

            Log::info('ocr.document.structured', [
                'ocr_document_id' => $document->id,
                'firm_count' => $parsed['firm_count'],
                'heading_count' => $parsed['heading_count'] ?? null,
                'skipped_blocks' => $parsed['skipped_blocks'] ?? 0,
                'parser_version' => $parsed['parser_version'],
                'completeness' => $completeness,
            ]);

            $this->dispatchMapping($document->fresh(), (int) $parsed['firm_count']);
        } catch (Throwable $exception) {
            Log::warning('ocr.document.structure_failed', [
                'ocr_document_id' => $document->id,
                'error' => class_basename($exception),
                'message' => $exception->getMessage(),
            ]);

            $document->update([
                'parse_status' => 'failed',
                'processing_progress' => 'Failed',
            ]);

            throw $exception;
        }

        return $document->fresh(['parsedFirms.members']);
    }

    private function dispatchMapping(OcrDocument $document, int $firmCount): void
    {
        if ($firmCount < 1) {
            $document->update(['processing_progress' => 'Completed']);

            return;
        }

        if (! filter_var(config('crm_mapping.queue_after_ocr_parse', true), FILTER_VALIDATE_BOOLEAN)) {
            $document->update(['processing_progress' => 'Completed']);

            return;
        }

        $syncMax = (int) config('crm_mapping.sync_max_firms', 50);
        $actorId = auth()->id();

        // Prefer inline mapping so Hostinger cron lag cannot leave firms Pending.
        if ($firmCount <= $syncMax) {
            try {
                $stats = $this->mappingService->processOcrDocument((int) $document->id, $actorId ? (int) $actorId : null);
                $this->storeMappingProgress($document, $stats);
                $document->update(['processing_progress' => 'Completed']);

                return;
            } catch (Throwable $e) {
                Log::warning('ocr.document.inline_mapping_failed', [
                    'ocr_document_id' => $document->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        MapOcrParsedFirmsJob::dispatch((int) $document->id, $actorId ? (int) $actorId : null);
        $document->update(['processing_progress' => 'Queued for Master mapping']);
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function storeMappingProgress(OcrDocument $document, array $stats): void
    {
        $structured = is_array($document->structured_data) ? $document->structured_data : [];
        $structured['mapping'] = [
            'processed' => (int) ($stats['processed'] ?? 0),
            'auto_created' => (int) ($stats['auto_created'] ?? 0),
            'auto_updated' => (int) ($stats['auto_updated'] ?? 0),
            'needs_review' => (int) ($stats['needs_review'] ?? 0),
            'conflicts' => (int) ($stats['conflicts'] ?? 0),
            'skipped' => (int) ($stats['skipped'] ?? 0),
            'mapped_at' => now()->toIso8601String(),
        ];
        $document->update(['structured_data' => $structured]);
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function evaluateCompleteness(OcrDocument $document, array $parsed, string $text): array
    {
        $pageCount = (int) ($document->page_count ?? 0);
        $pageMarkers = preg_match_all('/(?:^|\n)\s*(?:---+)?\s*Page\s+\d+/i', $text);
        $pagesWithText = max(1, (int) $pageMarkers);
        if ($pageCount < 1 && trim($text) !== '') {
            $pageCount = $pagesWithText;
        }

        $firmCount = (int) ($parsed['firm_count'] ?? 0);
        $headingCount = (int) ($parsed['heading_count'] ?? $firmCount);
        $skipped = (int) ($parsed['skipped_blocks'] ?? 0);
        $gapRatio = $headingCount > 0 ? max(0, $headingCount - $firmCount) / $headingCount : 0.0;

        $needsReview = false;
        $warnings = [];
        if ($pageCount > 0 && $pagesWithText < $pageCount) {
            $needsReview = true;
            $warnings[] = 'Not every uploaded page produced OCR text markers.';
        }
        if ($skipped > 0) {
            $needsReview = true;
            $warnings[] = $skipped.' firm block(s) were skipped as ambiguous.';
        }
        if ($headingCount >= 3 && $gapRatio >= 0.25) {
            $needsReview = true;
            $warnings[] = 'Parsed firm count is materially lower than detected headings/rows.';
        }
        if ($firmCount === 0 && trim($text) !== '') {
            $needsReview = true;
            $warnings[] = 'OCR text present but no firms were parsed.';
        }

        return [
            'page_count' => $pageCount,
            'pages_with_text_markers' => $pagesWithText,
            'firm_count' => $firmCount,
            'heading_count' => $headingCount,
            'skipped_blocks' => $skipped,
            'needs_review' => $needsReview,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $firms
     */
    private function replaceParsedRecords(OcrDocument $document, array $firms): void
    {
        // Replace-on-retry keeps staging idempotent (no duplicate firm rows).
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

                    $rawFirmName = $this->rawString($firmData['firm_name'] ?? ($firmData['raw_firm_name'] ?? null));
                    $rawFrn = $this->rawString($firmData['frn'] ?? null);
                    $rawGst = $this->rawString($firmData['gst_no'] ?? null);
                    $rawPan = $this->rawString($firmData['pan_no'] ?? null);
                    $rawPhone = $this->rawString($firmData['phone'] ?? null);
                    $rawEmail = $this->rawString($firmData['email'] ?? null);
                    $rawAddress = $this->rawString($firmData['address'] ?? null);
                    $rawCity = $this->rawString($firmData['city'] ?? null);
                    $rawState = $this->rawString($firmData['state'] ?? null);
                    $rawPincode = $this->rawString($firmData['pincode'] ?? null);

                    $firm = OcrParsedFirm::query()->create([
                        'ocr_document_id' => $document->id,
                        'sequence_no' => (int) ($firmData['sequence_no'] ?? 1),
                        'raw_firm_name' => $rawFirmName,
                        'firm_name' => $rawFirmName,
                        'normalized_firm_name' => $this->normalizer->firmName($rawFirmName),
                        'firm_type' => $firmData['firm_type'] ?? null,
                        'frn' => $rawFrn,
                        'gst_no' => $rawGst,
                        'pan_no' => $rawPan,
                        'address' => $rawAddress,
                        'city' => $rawCity,
                        'district' => $this->rawString($firmData['district'] ?? null),
                        'state' => $rawState,
                        'pincode' => $rawPincode,
                        'phone' => $rawPhone,
                        'email' => $rawEmail,
                        'website' => $this->rawString($firmData['website'] ?? null),
                        'partner_count' => count($members) > 0 ? count($members) : ($firmData['partner_count'] ?? null),
                        'review_status' => $firmData['review_status'] ?? OcrParsedFirm::REVIEW_PENDING,
                        'overall_confidence' => $firmData['overall_confidence'] ?? null,
                        'page_number' => $firmData['page_number'] ?? null,
                        'source_data' => [
                            'raw' => [
                                'firm_name' => $rawFirmName,
                                'frn' => $rawFrn,
                                'gst_no' => $rawGst,
                                'pan_no' => $rawPan,
                                'phone' => $rawPhone,
                                'email' => $rawEmail,
                                'address' => $rawAddress,
                                'city' => $rawCity,
                                'state' => $rawState,
                                'pincode' => $rawPincode,
                            ],
                            'normalized' => [
                                'firm_name' => $this->normalizer->firmName($rawFirmName),
                                'frn' => $this->normalizer->frn($rawFrn),
                                'gst_no' => $this->normalizer->gst($rawGst),
                                'pan_no' => $this->normalizer->pan($rawPan),
                                'phone' => $this->normalizer->phone($rawPhone),
                                'email' => $this->normalizer->email($rawEmail),
                                'pincode' => $this->normalizer->postalCode($rawPincode),
                            ],
                            'unclassified_lines' => $firmData['unclassified_lines'] ?? [],
                            'field_meta' => $firmData['field_meta'] ?? null,
                            'source_lines' => $firmData['source_lines'] ?? null,
                        ],
                        'field_meta' => $firmData['field_meta'] ?? null,
                    ]);

                    $memberRows = [];
                    foreach ($members as $index => $member) {
                        $rawCaName = $this->rawString($member['ca_name'] ?? ($member['raw_ca_name'] ?? null));
                        $rawMemNo = $this->rawString($member['membership_no'] ?? null);
                        $rawMemMobile = $this->rawString($member['mobile'] ?? null);
                        $rawMemEmail = $this->rawString($member['email'] ?? null);
                        $rawMemPan = $this->rawString($member['pan_no'] ?? null);
                        $memberRows[] = [
                            'ocr_parsed_firm_id' => $firm->id,
                            'sequence_no' => (int) ($member['sequence_no'] ?? ($index + 1)),
                            'raw_ca_name' => $rawCaName,
                            'ca_name' => $rawCaName,
                            'normalized_ca_name' => $this->normalizer->caName($rawCaName),
                            'membership_no' => $rawMemNo,
                            'mobile' => $rawMemMobile,
                            'email' => $rawMemEmail,
                            'pan_no' => $rawMemPan,
                            'role' => $member['role'] ?? null,
                            'is_primary' => (bool) ($member['is_primary'] ?? ($index === 0)),
                            'overall_confidence' => $member['overall_confidence'] ?? null,
                            'page_number' => $member['page_number'] ?? ($firmData['page_number'] ?? null),
                            'review_status' => 'pending',
                            'source_data' => json_encode([
                                'raw' => [
                                    'ca_name' => $rawCaName,
                                    'membership_no' => $rawMemNo,
                                    'mobile' => $rawMemMobile,
                                    'email' => $rawMemEmail,
                                    'pan_no' => $rawMemPan,
                                ],
                                'normalized' => [
                                    'ca_name' => $this->normalizer->caName($rawCaName),
                                    'membership_no' => $this->normalizer->membershipNumber($rawMemNo),
                                    'mobile' => $this->normalizer->phone($rawMemMobile),
                                    'email' => $this->normalizer->email($rawMemEmail),
                                    'pan_no' => $this->normalizer->pan($rawMemPan),
                                ],
                                'field_meta' => $member['field_meta'] ?? null,
                            ]),
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

    private function rawString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
