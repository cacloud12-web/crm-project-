<?php

namespace App\Services\Bulk;

class BulkImportErrorReportService
{
    public function __construct(
        private readonly BulkImportTemplateService $templateService,
    ) {}

    public function errorReportCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['row_number', 'original_data', 'error_reason']);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['row_number'] ?? '',
                json_encode($row['original_data'] ?? [], JSON_UNESCAPED_UNICODE),
                $row['error_reason'] ?? '',
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    public function reimportTemplateCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, BulkImportTemplateService::TEMPLATE_HEADERS);

        foreach ($rows as $row) {
            fputcsv($handle, $this->templateService->mapRowToTemplate($row['original_data'] ?? []));
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    public function fromValidationResults(array $evaluationRows, bool $failedOnly = true): array
    {
        $output = [];

        foreach ($evaluationRows as $result) {
            if ($failedOnly && ($result['status'] ?? '') === 'valid') {
                continue;
            }

            if (! $failedOnly && ($result['status'] ?? '') === 'valid') {
                continue;
            }

            $output[] = [
                'row_number' => $result['row_number'] ?? null,
                'original_data' => $result['data'] ?? [],
                'error_reason' => implode('; ', $result['errors'] ?? []),
                'status' => $result['status'] ?? 'invalid',
            ];
        }

        return $output;
    }
}
