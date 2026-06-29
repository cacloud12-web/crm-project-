<?php

namespace App\Services\Listing;

use App\Support\Listing\ListingQueryApplier;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListingExportService
{
    public function exportCsv(Builder $query, array $params, array $config, array $columns): StreamedResponse
    {
        $result = ListingQueryApplier::apply($query, array_merge($params, ['all' => true]), $config);
        $fileName = ($config['table'] ?? 'listing').'-export-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($result, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, array_values($columns));

            foreach ($result['items'] as $item) {
                $row = [];
                foreach (array_keys($columns) as $key) {
                    $row[] = data_get($item, $key);
                }
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
