<?php

namespace App\Services\Ocr;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only comparison of ca_masters firm names vs OCR-extracted firms from a PDF batch.
 * Never writes to business tables.
 */
class OcrMasterVsPdfFirmAuditService
{
    /**
     * Default ICAI directory PDF filenames (prop + part regions).
     *
     * @var list<string>
     */
    public const DEFAULT_BATCH_FILENAMES = [
        'westprop.pdf',
        'southprop.pdf',
        'northprop.pdf',
        'eastprop.pdf',
        'centralprop.pdf',
        'westpart.pdf',
        'eastpart.pdf',
        'southpart.pdf',
        'northpart.pdf',
        'centralpart.pdf',
    ];

    /**
     * @param  array{
     *   document_ids?: list<int>,
     *   filenames?: list<string>,
     *   use_default_batch?: bool,
     *   all_ocr?: bool,
     *   export?: string|null,
     *   include_deleted_masters?: bool
     * }  $options
     * @return array<string, mixed>
     */
    public function audit(array $options = []): array
    {
        $documentIds = $this->resolveDocumentIds($options);
        if ($documentIds === []) {
            throw new \InvalidArgumentException(
                'No OCR documents selected. Pass --document=, --documents=, --filenames=, --batch=default, or --all-ocr.'
            );
        }

        $docs = DB::table('ocr_documents')
            ->whereIn('id', $documentIds)
            ->orderBy('id')
            ->get(['id', 'original_filename', 'status']);

        $pdfFirms = $this->loadPdfFirms($documentIds);
        $masterFirms = $this->loadMasterFirms((bool) ($options['include_deleted_masters'] ?? false));

        $allKeys = array_values(array_unique(array_merge(
            array_keys($masterFirms),
            array_keys($pdfFirms),
        )));
        sort($allKeys);

        $rows = [];
        $both = 0;
        $masterOnly = 0;
        $pdfOnly = 0;

        foreach ($allKeys as $key) {
            $inMaster = isset($masterFirms[$key]);
            $inPdf = isset($pdfFirms[$key]);
            if ($inMaster && $inPdf) {
                $status = 'In Both';
                $both++;
            } elseif ($inMaster) {
                $status = 'Master Only';
                $masterOnly++;
            } else {
                $status = 'PDF Only';
                $pdfOnly++;
            }

            $display = $inMaster
                ? ($masterFirms[$key]['display'] ?? $key)
                : ($pdfFirms[$key]['display'] ?? $key);

            $docIds = $inPdf
                ? implode(',', $pdfFirms[$key]['document_ids'] ?? [])
                : '';

            $rows[] = [
                'Firm Name' => $display,
                'Normalized Firm Name' => $key,
                'Present In Master' => $inMaster ? 'Yes' : 'No',
                'Present In PDF' => $inPdf ? 'Yes' : 'No',
                'Source Document IDs' => $docIds,
                'Match Status' => $status,
            ];
        }

        $export = $options['export'] ?? null;
        if ($export === null || $export === '') {
            $export = storage_path('app/audits/master-vs-pdf-firms.csv');
        }
        $this->writeCsv((string) $export, $rows);

        return [
            'totals' => [
                'master_firms' => count($masterFirms),
                'pdf_firms' => count($pdfFirms),
                'both' => $both,
                'master_only' => $masterOnly,
                'pdf_only' => $pdfOnly,
                'union' => count($allKeys),
            ],
            'documents' => $docs->map(static fn ($d) => [
                'id' => $d->id,
                'filename' => $d->original_filename,
                'status' => $d->status,
            ])->all(),
            'export_path' => $export,
            'row_count' => count($rows),
        ];
    }

    /**
     * Normalize firm name for comparison: trim, uppercase, collapse spaces, strip punctuation.
     */
    public function normalizeFirmName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Treat & as AND before stripping punctuation so "A & CO" ≡ "A AND CO".
        $value = str_replace('&', ' AND ', $value);
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return mb_strtoupper($value, 'UTF-8');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<int>
     */
    private function resolveDocumentIds(array $options): array
    {
        if (! empty($options['all_ocr'])) {
            return DB::table('ocr_documents')
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->all();
        }

        if (! empty($options['document_ids']) && is_array($options['document_ids'])) {
            return array_values(array_unique(array_map('intval', $options['document_ids'])));
        }

        $filenames = [];
        if (! empty($options['use_default_batch'])) {
            $filenames = self::DEFAULT_BATCH_FILENAMES;
        }
        if (! empty($options['filenames']) && is_array($options['filenames'])) {
            $filenames = array_merge($filenames, $options['filenames']);
        }
        $filenames = array_values(array_unique(array_filter(array_map(
            static fn ($f) => trim((string) $f),
            $filenames
        ))));

        if ($filenames === []) {
            return [];
        }

        // Prefer completed docs; if duplicates by filename, take latest id per filename.
        $rows = DB::table('ocr_documents')
            ->whereIn('original_filename', $filenames)
            ->orderByDesc('id')
            ->get(['id', 'original_filename']);

        $picked = [];
        foreach ($rows as $row) {
            $fn = (string) $row->original_filename;
            if (isset($picked[$fn])) {
                continue;
            }
            $picked[$fn] = (int) $row->id;
        }

        return array_values($picked);
    }

    /**
     * @param  list<int>  $documentIds
     * @return array<string, array{display: string, document_ids: list<int>}>
     */
    private function loadPdfFirms(array $documentIds): array
    {
        $map = [];
        DB::table('ocr_parsed_firms')
            ->whereIn('ocr_document_id', $documentIds)
            ->whereNotNull('firm_name')
            ->where('firm_name', '!=', '')
            ->orderBy('id')
            ->select(['id', 'ocr_document_id', 'firm_name'])
            ->chunkById(2000, function ($chunk) use (&$map) {
                foreach ($chunk as $row) {
                    $key = $this->normalizeFirmName((string) $row->firm_name);
                    if ($key === null) {
                        continue;
                    }
                    if (! isset($map[$key])) {
                        $map[$key] = [
                            'display' => trim((string) $row->firm_name),
                            'document_ids' => [],
                        ];
                    }
                    $docId = (int) $row->ocr_document_id;
                    if (! in_array($docId, $map[$key]['document_ids'], true)) {
                        $map[$key]['document_ids'][] = $docId;
                    }
                }
            });

        foreach ($map as &$entry) {
            sort($entry['document_ids']);
        }
        unset($entry);

        return $map;
    }

    /**
     * @return array<string, array{display: string}>
     */
    private function loadMasterFirms(bool $includeDeleted): array
    {
        $query = DB::table('ca_masters')
            ->whereNotNull('firm_name')
            ->where('firm_name', '!=', '');

        if (! $includeDeleted && Schema::hasColumn('ca_masters', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $map = [];
        $query->orderBy('ca_id')
            ->select(['ca_id', 'firm_name'])
            ->chunkById(2000, function ($chunk) use (&$map) {
                foreach ($chunk as $row) {
                    $key = $this->normalizeFirmName((string) $row->firm_name);
                    if ($key === null) {
                        continue;
                    }
                    if (! isset($map[$key])) {
                        $map[$key] = [
                            'display' => trim((string) $row->firm_name),
                        ];
                    }
                }
            }, 'ca_id');

        return $map;
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && ! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Unable to open CSV for write: '.$path);
        }

        $headers = [
            'Firm Name',
            'Present In Master',
            'Present In PDF',
            'Source Document IDs',
            'Match Status',
        ];
        fputcsv($fh, $headers);
        foreach ($rows as $row) {
            fputcsv($fh, [
                $row['Firm Name'] ?? '',
                $row['Present In Master'] ?? '',
                $row['Present In PDF'] ?? '',
                $row['Source Document IDs'] ?? '',
                $row['Match Status'] ?? '',
            ]);
        }
        fclose($fh);
    }
}
