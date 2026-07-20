<?php

namespace App\Services\Ocr;

use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Models\OcrParsedMember;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

class OcrSelectedTransferService
{
    private const DOCUMENT_NULLABLE_FKS = ['ca_id', 'import_batch_id', 'corrected_by'];

    private const FIRM_NULLABLE_FKS = ['crm_ca_id', 'matched_ca_id', 'matched_reference_firm_id'];

    private const MEMBER_NULLABLE_FKS = ['matched_reference_member_id'];

    private const TABLES = [
        'documents' => 'ocr_documents',
        'firms' => 'ocr_parsed_firms',
        'members' => 'ocr_parsed_members',
    ];

    /** @return array{batch_id: string, path: string, manifest: array<string, mixed>} */
    public function export(array $documentIds, bool $dryRun = false, ?OutputInterface $output = null): array
    {
        $documentIds = $this->normalizeIds($documentIds);
        $documents = $this->loadExportDocuments($documentIds);
        $firmIds = OcrParsedFirm::query()
            ->whereIn('ocr_document_id', $documentIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $memberQuery = OcrParsedMember::query()->whereIn('ocr_parsed_firm_id', $firmIds);
        $firmCount = count($firmIds);
        $memberCount = (clone $memberQuery)->count();
        $this->assertNoOrphans($documentIds, $firmIds);

        $batchId = now()->format('Ymd-His').'-'.substr(sha1(implode(',', $documentIds)), 0, 8);
        $basePath = storage_path('app/ocr-transfer/'.$batchId);

        if ($dryRun) {
            return [
                'batch_id' => $batchId,
                'path' => $basePath,
                'manifest' => $this->buildManifestPreview($batchId, $documents, $firmCount, $memberCount, $documentIds),
            ];
        }

        if (! is_dir($basePath) && ! mkdir($basePath, 0755, true) && ! is_dir($basePath)) {
            throw new RuntimeException("Unable to create export directory: {$basePath}");
        }

        $docColumns = $this->tableColumns('ocr_documents');
        $firmColumns = $this->tableColumns('ocr_parsed_firms');
        $memberColumns = $this->tableColumns('ocr_parsed_members');

        $this->exportTableNdjson($basePath.'/documents.ndjson', 'ocr_documents', $docColumns, function ($query) use ($documentIds) {
            $query->whereIn('id', $documentIds)->orderBy('id');
        }, $output, 'documents');

        $this->exportTableNdjson($basePath.'/firms.ndjson', 'ocr_parsed_firms', $firmColumns, function ($query) use ($documentIds) {
            $query->whereIn('ocr_document_id', $documentIds)->orderBy('id');
        }, $output, 'firms');

        $this->exportTableNdjson($basePath.'/members.ndjson', 'ocr_parsed_members', $memberColumns, function ($query) use ($firmIds) {
            $query->whereIn('ocr_parsed_firm_id', $firmIds)->orderBy('id');
        }, $output, 'members');

        $manifest = $this->buildManifest(
            $batchId,
            $documents,
            $documentIds,
            $docColumns,
            $firmColumns,
            $memberColumns,
            $basePath,
        );
        file_put_contents($basePath.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return ['batch_id' => $batchId, 'path' => $basePath, 'manifest' => $manifest];
    }

    /** @return array<string, mixed> */
    public function import(
        string $packagePath,
        int $uploadedBy,
        int $chunkSize = 500,
        bool $dryRun = false,
        bool $resume = false,
        ?OutputInterface $output = null,
    ): array {
        $packagePath = $this->resolvePackagePath($packagePath);
        $manifestPath = $packagePath.'/manifest.json';
        if (! is_file($manifestPath)) {
            throw new RuntimeException("Manifest not found at {$manifestPath}");
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest)) {
            throw new RuntimeException('Invalid manifest.json');
        }

        $this->validateManifestChecksum($manifest, $packagePath);
        $this->assertSchemaCompatible($manifest);
        $this->assertBatchNotImported($manifest['batch_id'] ?? '', $resume);
        $this->assertUploadedByValid($uploadedBy);
        $this->assertNoDuplicateFilenames($manifest['filenames'] ?? []);

        $statePath = $this->importStatePath($manifest['batch_id']);
        $state = $resume && is_file($statePath)
            ? json_decode((string) file_get_contents($statePath), true) ?: []
            : [];

        $summary = [
            'batch_id' => $manifest['batch_id'],
            'documents_imported' => (int) ($state['documents_imported'] ?? 0),
            'firms_imported' => (int) ($state['firms_imported'] ?? 0),
            'members_imported' => (int) ($state['members_imported'] ?? 0),
            'document_id_map' => (array) ($state['document_id_map'] ?? []),
            'firm_id_map' => (array) ($state['firm_id_map'] ?? []),
            'dry_run' => $dryRun,
        ];

        if ($dryRun) {
            $summary['would_import'] = [
                'documents' => (int) ($manifest['documents']['count'] ?? 0),
                'firms' => (int) ($manifest['firms']['count'] ?? 0),
                'members' => (int) ($manifest['members']['count'] ?? 0),
            ];

            return $summary;
        }

        DB::transaction(function () use (
            $packagePath, $manifest, $uploadedBy, $chunkSize, $resume, $output, $statePath, &$summary, $state
        ) {
            if (empty($state['documents_done'])) {
                $summary = $this->importDocuments(
                    $packagePath,
                    $manifest,
                    $uploadedBy,
                    $chunkSize,
                    $output,
                    $summary,
                );
                $state['documents_done'] = true;
                $state['documents_imported'] = $summary['documents_imported'];
                $state['document_id_map'] = $summary['document_id_map'];
                $this->writeImportState($statePath, $state);
            } else {
                $summary['document_id_map'] = $this->normalizeIdMap((array) ($state['document_id_map'] ?? []));
                $summary['documents_imported'] = (int) ($state['documents_imported'] ?? 0);
            }

            if (empty($state['firms_done'])) {
                $summary = $this->importFirms(
                    $packagePath,
                    $manifest,
                    $chunkSize,
                    $output,
                    $summary,
                );
                $state['firms_done'] = true;
                $state['firms_imported'] = $summary['firms_imported'];
                $state['firm_id_map'] = $summary['firm_id_map'];
                $this->writeImportState($statePath, $state);
            } else {
                $summary['firm_id_map'] = $this->normalizeIdMap((array) ($state['firm_id_map'] ?? []));
                $summary['firms_imported'] = (int) ($state['firms_imported'] ?? 0);
            }

            if (empty($state['members_done'])) {
                $summary = $this->importMembers(
                    $packagePath,
                    $manifest,
                    $chunkSize,
                    $output,
                    $summary,
                );
                $state['members_done'] = true;
                $state['members_imported'] = $summary['members_imported'];
                $this->writeImportState($statePath, $state);
            } else {
                $summary['members_imported'] = (int) ($state['members_imported'] ?? 0);
            }

            $this->verifyImportedCounts($manifest, $summary);
            $this->verifyNoOrphansAfterImport($summary);
            $this->markBatchImported($manifest, $summary);
            @unlink($statePath);
        });

        return $summary;
    }

    /** @param  list<int>  $documentIds */
    private function loadExportDocuments(array $documentIds): Collection
    {
        $documents = OcrDocument::withTrashed()
            ->whereIn('id', $documentIds)
            ->orderBy('id')
            ->get();

        if ($documents->count() !== count($documentIds)) {
            $missing = array_values(array_diff($documentIds, $documents->pluck('id')->map(fn ($id) => (int) $id)->all()));
            throw new RuntimeException('Missing OCR documents: '.implode(', ', $missing));
        }

        $notCompleted = $documents
            ->filter(fn (OcrDocument $doc) => $doc->status !== OcrDocument::STATUS_COMPLETED)
            ->pluck('id')
            ->all();

        if ($notCompleted !== []) {
            throw new RuntimeException('All selected documents must be completed. Not completed: '.implode(', ', $notCompleted));
        }

        return $documents;
    }

    /** @param  list<int>  $documentIds  @param  list<int>  $firmIds */
    private function assertNoOrphans(array $documentIds, array $firmIds): void
    {
        $orphanFirms = OcrParsedFirm::query()
            ->whereIn('ocr_document_id', $documentIds)
            ->whereNotIn('id', $firmIds)
            ->count();
        $orphanMembers = $firmIds === []
            ? 0
            : (int) DB::table('ocr_parsed_members as m')
                ->leftJoin('ocr_parsed_firms as f', 'f.id', '=', 'm.ocr_parsed_firm_id')
                ->whereIn('m.ocr_parsed_firm_id', $firmIds)
                ->where(function ($query) use ($documentIds) {
                    $query->whereNull('f.id')
                        ->orWhereNotIn('f.ocr_document_id', $documentIds);
                })
                ->count();

        if ($orphanFirms > 0 || $orphanMembers > 0) {
            throw new RuntimeException("Orphan check failed: firms={$orphanFirms}, members={$orphanMembers}");
        }
    }

    /** @return list<string> */
    private function tableColumns(string $table): array
    {
        return Schema::getColumnListing($table);
    }

  /**
     * @param  callable(\Illuminate\Database\Query\Builder): void  $scope
     */
    private function exportTableNdjson(
        string $path,
        string $table,
        array $columns,
        callable $scope,
        ?OutputInterface $output,
        string $label,
    ): void {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Cannot write {$path}");
        }

        $query = DB::table($table);
        $scope($query);
        $total = (clone $query)->count();
        $written = 0;
        $query->orderBy('id')->chunk(500, function ($rows) use ($handle, $columns, &$written, $output, $label, $total) {
            foreach ($rows as $row) {
                $payload = [];
                foreach ($columns as $column) {
                    $payload[$column] = $row->{$column} ?? null;
                }
                fwrite($handle, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
                $written++;
                if ($output && $written % 250 === 0) {
                    $output->writeln("  {$label}: {$written}/{$total}");
                }
            }
        });

        fclose($handle);
        if ($output) {
            $output->writeln("  {$label}: {$written} rows exported");
        }
    }

    /** @param  Collection<int, OcrDocument>  $documents */
    private function buildManifest(
        string $batchId,
        Collection $documents,
        array $documentIds,
        array $docColumns,
        array $firmColumns,
        array $memberColumns,
        string $basePath,
    ): array {
        $manifest = $this->buildManifestPreview(
            $batchId,
            $documents,
            (int) DB::table('ocr_parsed_firms')->whereIn('ocr_document_id', $documentIds)->count(),
            (int) DB::table('ocr_parsed_members')->whereIn(
                'ocr_parsed_firm_id',
                DB::table('ocr_parsed_firms')->whereIn('ocr_document_id', $documentIds)->pluck('id'),
            )->count(),
            $documentIds,
        );

        foreach (['documents' => 'documents.ndjson', 'firms' => 'firms.ndjson', 'members' => 'members.ndjson'] as $key => $file) {
            $manifest[$key]['columns'] = match ($key) {
                'documents' => $docColumns,
                'firms' => $firmColumns,
                'members' => $memberColumns,
            };
            $manifest[$key]['file'] = $file;
            $manifest[$key]['sha256'] = hash_file('sha256', $basePath.'/'.$file);
        }

        $manifest['checksum'] = $this->manifestChecksum($manifest);

        return $manifest;
    }

    /** @param  Collection<int, OcrDocument>  $documents */
    private function buildManifestPreview(
        string $batchId,
        Collection $documents,
        int $firmCount,
        int $memberCount,
        array $documentIds,
    ): array {
        return [
            'batch_id' => $batchId,
            'exported_at' => now()->toIso8601String(),
            'source_connection' => config('database.default'),
            'source_document_ids' => $documentIds,
            'filenames' => $documents->pluck('original_filename')->values()->all(),
            'documents' => ['count' => $documents->count()],
            'firms' => ['count' => $firmCount],
            'members' => ['count' => $memberCount],
            'orphan_checks' => ['orphan_firms' => 0, 'orphan_members' => 0],
        ];
    }

    private function manifestChecksum(array $manifest): string
    {
        $copy = $manifest;
        unset($copy['checksum']);

        return hash('sha256', json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function validateManifestChecksum(array $manifest, string $packagePath): void
    {
        $expected = (string) ($manifest['checksum'] ?? '');
        $actual = $this->manifestChecksum($manifest);
        if ($expected === '' || ! hash_equals($expected, $actual)) {
            throw new RuntimeException('Manifest checksum validation failed.');
        }

        foreach (['documents', 'firms', 'members'] as $section) {
            $file = $packagePath.'/'.($manifest[$section]['file'] ?? "{$section}.ndjson");
            $hash = (string) ($manifest[$section]['sha256'] ?? '');
            if ($hash === '' || ! is_file($file) || ! hash_equals($hash, hash_file('sha256', $file))) {
                throw new RuntimeException("Checksum mismatch for {$section} file.");
            }
        }
    }

    private function assertSchemaCompatible(array $manifest): void
    {
        foreach (self::TABLES as $section => $table) {
            $manifestColumns = $manifest[$section]['columns'] ?? null;
            if (! is_array($manifestColumns) || $manifestColumns === []) {
                throw new RuntimeException("Manifest missing columns for {$section}.");
            }

            $liveColumns = $this->tableColumns($table);
            $missingOnLive = array_values(array_diff($manifestColumns, $liveColumns));
            if ($missingOnLive !== []) {
                throw new RuntimeException(
                    "Schema incompatible for {$table}. Missing on live: ".implode(', ', $missingOnLive),
                );
            }
        }
    }

    private function assertBatchNotImported(string $batchId, bool $resume): void
    {
        if ($batchId === '') {
            throw new RuntimeException('Manifest batch_id is required.');
        }

        $registry = $this->importRegistryPath($batchId);
        if (is_file($registry) && ! $resume) {
            throw new RuntimeException("Batch {$batchId} was already imported.");
        }
    }

    private function assertUploadedByValid(int $uploadedBy): void
    {
        if (! User::query()->whereKey($uploadedBy)->exists()) {
            throw new RuntimeException("uploaded_by user #{$uploadedBy} does not exist on live.");
        }
    }

    /** @param  list<string>  $filenames */
    private function assertNoDuplicateFilenames(array $filenames): void
    {
        $existing = OcrDocument::withTrashed()
            ->whereIn('original_filename', $filenames)
            ->pluck('original_filename')
            ->all();

        if ($existing !== []) {
            throw new RuntimeException(
                'Duplicate filenames already exist on live: '.implode(', ', array_unique($existing)),
            );
        }
    }

    /** @param  array<string, mixed>  $manifest */
    private function importDocuments(
        string $packagePath,
        array $manifest,
        int $uploadedBy,
        int $chunkSize,
        ?OutputInterface $output,
        array $summary,
    ): array {
        unset($chunkSize);
        $columns = $this->importableColumns('documents', $manifest);
        /** @var array<int, int> $documentIdMap */
        $documentIdMap = [];
        $imported = 0;

        foreach ($this->readNdjson($packagePath.'/'.($manifest['documents']['file'] ?? 'documents.ndjson')) as $row) {
            $oldDocumentId = (int) ($row['id'] ?? 0);
            if ($oldDocumentId <= 0) {
                throw new RuntimeException('Document row is missing a valid local id.');
            }

            unset($row['id']);
            foreach (self::DOCUMENT_NULLABLE_FKS as $nullable) {
                $row[$nullable] = null;
            }
            $row['uploaded_by'] = $uploadedBy;

            $insertRow = $this->filterRow($row, $columns);
            $newDocumentId = (int) DB::table('ocr_documents')->insertGetId($insertRow);
            $documentIdMap[$oldDocumentId] = $newDocumentId;
            $imported++;

            if ($output && $imported % 250 === 0) {
                $output->writeln("  documents: {$imported}");
            }
        }

        if ($output) {
            $output->writeln("  documents imported: {$imported}");
        }

        $summary['documents_imported'] = $imported;
        $summary['document_id_map'] = $documentIdMap;

        return $summary;
    }

    /** @param  array<string, mixed>  $manifest */
    private function importFirms(
        string $packagePath,
        array $manifest,
        int $chunkSize,
        ?OutputInterface $output,
        array $summary,
    ): array {
        unset($chunkSize);
        $columns = $this->importableColumns('firms', $manifest);
        /** @var array<int, int> $documentIdMap */
        $documentIdMap = $this->normalizeIdMap($summary['document_id_map'] ?? []);
        /** @var array<int, int> $firmIdMap */
        $firmIdMap = [];
        $imported = 0;

        foreach ($this->readNdjson($packagePath.'/'.($manifest['firms']['file'] ?? 'firms.ndjson')) as $row) {
            $oldFirmId = (int) ($row['id'] ?? 0);
            $oldDocumentId = (int) ($row['ocr_document_id'] ?? 0);
            if ($oldFirmId <= 0) {
                throw new RuntimeException('Firm row is missing a valid local id.');
            }
            if ($oldDocumentId <= 0) {
                throw new RuntimeException("Firm {$oldFirmId} is missing ocr_document_id.");
            }

            unset($row['id']);
            foreach (self::FIRM_NULLABLE_FKS as $nullable) {
                $row[$nullable] = null;
            }

            $insertRow = $this->filterRow($row, $columns);
            $insertRow['ocr_document_id'] = $this->mapDocumentId($oldDocumentId, $documentIdMap);

            $newFirmId = (int) DB::table('ocr_parsed_firms')->insertGetId($insertRow);
            $firmIdMap[$oldFirmId] = $newFirmId;
            $imported++;

            if ($output && $imported % 250 === 0) {
                $output->writeln("  firms: {$imported}");
            }
        }

        if ($output) {
            $output->writeln("  firms imported: {$imported}");
        }

        $summary['firms_imported'] = $imported;
        $summary['firm_id_map'] = $firmIdMap;

        return $summary;
    }

    /** @param  array<string, mixed>  $manifest */
    private function importMembers(
        string $packagePath,
        array $manifest,
        int $chunkSize,
        ?OutputInterface $output,
        array $summary,
    ): array {
        $columns = $this->importableColumns('members', $manifest);
        /** @var array<int, int> $firmIdMap */
        $firmIdMap = $this->normalizeIdMap($summary['firm_id_map'] ?? []);
        $imported = 0;
        $buffer = [];

        foreach ($this->readNdjson($packagePath.'/'.($manifest['members']['file'] ?? 'members.ndjson')) as $row) {
            $oldFirmId = (int) ($row['ocr_parsed_firm_id'] ?? 0);
            if ($oldFirmId <= 0) {
                throw new RuntimeException('Member row is missing ocr_parsed_firm_id.');
            }

            unset($row['id']);
            foreach (self::MEMBER_NULLABLE_FKS as $nullable) {
                $row[$nullable] = null;
            }

            $insertRow = $this->filterRow($row, $columns);
            $insertRow['ocr_parsed_firm_id'] = $this->mapFirmId($oldFirmId, $firmIdMap);
            $buffer[] = $insertRow;

            if (count($buffer) >= $chunkSize) {
                DB::table('ocr_parsed_members')->insert($buffer);
                $imported += count($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            DB::table('ocr_parsed_members')->insert($buffer);
            $imported += count($buffer);
        }

        if ($output) {
            $output->writeln("  members imported: {$imported}");
        }

        $summary['members_imported'] = $imported;

        return $summary;
    }

    /** @param  array<int, int>  $documentIdMap */
    private function mapDocumentId(int $oldDocumentId, array $documentIdMap): int
    {
        if (! isset($documentIdMap[$oldDocumentId])) {
            throw new RuntimeException(
                "Missing document ID mapping for local document {$oldDocumentId}",
            );
        }

        return (int) $documentIdMap[$oldDocumentId];
    }

    /** @param  array<int, int>  $firmIdMap */
    private function mapFirmId(int $oldFirmId, array $firmIdMap): int
    {
        if (! isset($firmIdMap[$oldFirmId])) {
            throw new RuntimeException(
                "Missing firm ID mapping for local firm {$oldFirmId}",
            );
        }

        return (int) $firmIdMap[$oldFirmId];
    }

    /** @param  array<int|string, int|string>  $map  @return array<int, int> */
    private function normalizeIdMap(array $map): array
    {
        $normalized = [];
        foreach ($map as $oldId => $newId) {
            $normalized[(int) $oldId] = (int) $newId;
        }

        return $normalized;
    }

    /** @param  array<string, mixed>  $manifest */
    private function importableColumns(string $section, array $manifest): array
    {
        $manifestColumns = $manifest[$section]['columns'] ?? [];
        $liveColumns = $this->tableColumns(self::TABLES[$section]);

        $columns = array_values(array_intersect($manifestColumns, $liveColumns, array_diff($liveColumns, ['id'])));
        $required = match ($section) {
            'firms' => ['ocr_document_id'],
            'members' => ['ocr_parsed_firm_id'],
            default => [],
        };

        foreach ($required as $column) {
            if (in_array($column, $liveColumns, true) && ! in_array($column, $columns, true)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /** @param  array<string, mixed>  $row  @return array<string, mixed> */
    private function filterRow(array $row, array $columns): array
    {
        return array_intersect_key($row, array_flip($columns));
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function readNdjson(string $path): \Generator
    {
        if (! is_file($path)) {
            throw new RuntimeException("NDJSON file not found: {$path}");
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot read {$path}");
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                throw new RuntimeException("Invalid NDJSON line in {$path}");
            }
            yield $decoded;
        }

        fclose($handle);
    }

    /** @param  array<string, mixed>  $manifest  @param  array<string, mixed>  $summary */
    private function verifyImportedCounts(array $manifest, array $summary): void
    {
        foreach (['documents', 'firms', 'members'] as $section) {
            $expected = (int) ($manifest[$section]['count'] ?? 0);
            $actual = (int) ($summary["{$section}_imported"] ?? 0);
            if ($expected !== $actual) {
                throw new RuntimeException("Count mismatch for {$section}: expected {$expected}, got {$actual}");
            }
        }
    }

    /** @param  array<string, mixed>  $summary */
    private function verifyNoOrphansAfterImport(array $summary): void
    {
        $docIds = array_values($this->normalizeIdMap($summary['document_id_map'] ?? []));
        $firmIds = array_values($this->normalizeIdMap($summary['firm_id_map'] ?? []));

        $orphanFirms = $firmIds === []
            ? 0
            : DB::table('ocr_parsed_firms')
                ->whereIn('id', $firmIds)
                ->whereNotIn('ocr_document_id', $docIds)
                ->count();

        $orphanMembers = $firmIds === []
            ? 0
            : (int) DB::table('ocr_parsed_members as m')
                ->leftJoin('ocr_parsed_firms as f', 'f.id', '=', 'm.ocr_parsed_firm_id')
                ->whereIn('m.ocr_parsed_firm_id', $firmIds)
                ->whereNull('f.id')
                ->count();

        if ($orphanFirms > 0 || $orphanMembers > 0) {
            throw new RuntimeException("Post-import orphan check failed: firms={$orphanFirms}, members={$orphanMembers}");
        }
    }

    /** @param  array<string, mixed>  $manifest  @param  array<string, mixed>  $summary */
    private function markBatchImported(array $manifest, array $summary): void
    {
        $path = $this->importRegistryPath((string) $manifest['batch_id']);
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode([
            'batch_id' => $manifest['batch_id'],
            'imported_at' => now()->toIso8601String(),
            'documents_imported' => $summary['documents_imported'],
            'firms_imported' => $summary['firms_imported'],
            'members_imported' => $summary['members_imported'],
            'document_id_map' => $summary['document_id_map'],
            'firm_id_map' => $summary['firm_id_map'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    private function writeImportState(string $path, array $state): void
    {
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    private function importRegistryPath(string $batchId): string
    {
        return storage_path('app/ocr-transfer/.imported/'.$batchId.'.json');
    }

    private function importStatePath(string $batchId): string
    {
        return storage_path('app/ocr-transfer/.import-state/'.$batchId.'.json');
    }

    private function resolvePackagePath(string $packagePath): string
    {
        $packagePath = Str::startsWith($packagePath, DIRECTORY_SEPARATOR)
            ? $packagePath
            : base_path($packagePath);

        if (! is_dir($packagePath)) {
            throw new RuntimeException("Package directory not found: {$packagePath}");
        }

        return rtrim($packagePath, DIRECTORY_SEPARATOR);
    }

    /** @param  list<int|string>  $documentIds  @return list<int> */
    private function normalizeIds(array $documentIds): array
    {
        return collect($documentIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
