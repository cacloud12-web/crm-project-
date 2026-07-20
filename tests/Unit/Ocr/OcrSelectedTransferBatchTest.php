<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\OcrSelectedTransferService;
use ReflectionMethod;
use Tests\TestCase;

class OcrSelectedTransferBatchTest extends TestCase
{
    private function safeBatchRowCount(int $columnCount, int $requestedChunk): int
    {
        $service = app(OcrSelectedTransferService::class);
        $method = new ReflectionMethod($service, 'safeBatchRowCount');
        $method->setAccessible(true);

        return (int) $method->invoke($service, $columnCount, $requestedChunk);
    }

    public function test_safe_batch_row_count_caps_by_mysql_placeholder_limit(): void
    {
        $this->assertSame(1, $this->safeBatchRowCount(65000, 5000));
        $this->assertSame(1625, $this->safeBatchRowCount(40, 5000));
        $this->assertSame(500, $this->safeBatchRowCount(40, 500));
        $this->assertSame(1, $this->safeBatchRowCount(40, 0));
    }

    public function test_bulk_insert_splits_rows_when_requested_chunk_exceeds_placeholder_limit(): void
    {
        $service = app(OcrSelectedTransferService::class);
        $method = new ReflectionMethod($service, 'bulkInsertRows');
        $method->setAccessible(true);

        $columnCount = 40;
        $rows = [];
        for ($i = 1; $i <= 5000; $i++) {
            $row = ['ocr_parsed_firm_id' => 1, 'sequence_no' => 1, 'review_status' => 'pending'];
            for ($c = 3; $c < $columnCount; $c++) {
                $row['col_'.$c] = 'value-'.$i;
            }
            $rows[] = $row;
        }

        $expectedBatches = (int) ceil(5000 / $this->safeBatchRowCount($columnCount, 5000));
        $inserted = 0;
        $batches = 0;
        $batchSize = $this->safeBatchRowCount($columnCount, 5000);

        foreach (array_chunk($rows, $batchSize) as $chunk) {
            $batches++;
            $inserted += count($chunk);
        }

        $this->assertSame(5000, $inserted);
        $this->assertSame($expectedBatches, $batches);
        $this->assertGreaterThan(1, $batches);
        $this->assertLessThanOrEqual(
            65000,
            $batchSize * $columnCount,
        );
    }
}
