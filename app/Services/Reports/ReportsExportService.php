<?php

namespace App\Services\Reports;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsExportService
{
    public function streamCsv(string $filename, array $columns, array $rows): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        return response()->streamDownload(function () use ($columns, $rows) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, array_values($columns));

            $keys = array_keys($columns);
            foreach ($rows as $row) {
                $line = [];
                foreach ($keys as $key) {
                    $line[] = $row[$key] ?? '';
                }
                fputcsv($handle, $line);
            }

            fclose($handle);
        }, $filename, $headers);
    }

    public function streamPdf(string $filename, array $columns, array $rows, string $title): Response
    {
        $html = view('reports.export-pdf', [
            'title' => $title,
            'columns' => array_values($columns),
            'columnKeys' => array_keys($columns),
            'rows' => $rows,
            'generatedAt' => now()->format('d M Y H:i'),
        ])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $pdfName = preg_replace('/\.csv$/i', '.pdf', $filename) ?: $filename.'.pdf';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdfName.'"',
        ]);
    }
}
