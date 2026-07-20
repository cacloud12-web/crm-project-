<?php

namespace App\Services\Ocr;

use App\Exceptions\DocumentAi\DocumentAiConfigurationException;
use App\Exceptions\DocumentAi\DocumentAiProcessingException;
use App\Exceptions\Ocr\OcrProviderException;
use App\Models\OcrDocument;
use App\Services\DocumentAi\GoogleDocumentAiService;
use Google\ApiCore\ApiException;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Cloud\DocumentAI\V1\BatchDocumentsInputConfig;
use Google\Cloud\DocumentAI\V1\BatchProcessRequest;
use Google\Cloud\DocumentAI\V1\Client\DocumentProcessorServiceClient;
use Google\Cloud\DocumentAI\V1\DocumentOutputConfig;
use Google\Cloud\DocumentAI\V1\DocumentOutputConfig\GcsOutputConfig;
use Google\Cloud\DocumentAI\V1\GcsDocument;
use Google\Cloud\DocumentAI\V1\GcsDocuments;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class GoogleDocumentAiBatchService
{
    public function __construct(
        private readonly GoogleDocumentAiService $documentAi,
        private readonly GoogleCloudStorageService $storage,
    ) {}

    /**
     * Upload local OCR file to GCS and submit batchProcessDocuments.
     *
     * @return array{operation_name: string, gcs_input_uri: string, gcs_output_uri: string}
     */
    public function submit(OcrDocument $document, string $binary): array
    {
        $this->documentAi->validateConfiguration();
        $this->storage->validateConfiguration();

        $inputBucket = (string) config('document-ai.gcs.input_bucket');
        $outputBucket = (string) config('document-ai.gcs.output_bucket');
        $extension = pathinfo((string) $document->stored_filename, PATHINFO_EXTENSION) ?: 'pdf';
        $objectName = $this->storage->buildInputObjectPath((int) $document->id, $extension);
        $outputPrefix = $this->storage->buildOutputPrefix((int) $document->id);

        $inputUri = $this->storage->uploadObject(
            $inputBucket,
            $objectName,
            $binary,
            $document->mime_type ?: 'application/pdf',
        );
        $outputUri = 'gs://'.$outputBucket.'/'.$outputPrefix;

        $projectId = (string) config('document-ai.project_id');
        $location = (string) config('document-ai.location', 'us');
        $processorId = (string) config('document-ai.processor_id');
        $credentialsPath = $this->documentAi->resolveCredentialsPath();
        $endpoint = (string) config('document-ai.api_endpoint');

        $clientConfig = [
            'apiEndpoint' => $endpoint,
        ];
        if ($credentialsPath !== null) {
            $clientConfig['credentials'] = $credentialsPath;
        }

        $client = new DocumentProcessorServiceClient($clientConfig);

        try {
            $gcsDocument = (new GcsDocument)
                ->setGcsUri($inputUri)
                ->setMimeType($document->mime_type ?: 'application/pdf');

            $gcsDocuments = (new GcsDocuments)->setDocuments([$gcsDocument]);
            $inputConfig = (new BatchDocumentsInputConfig)->setGcsDocuments($gcsDocuments);
            $outputConfig = (new DocumentOutputConfig)->setGcsOutputConfig(
                (new GcsOutputConfig)->setGcsUri($outputUri),
            );

            $request = (new BatchProcessRequest)
                ->setName($client->processorName($projectId, $location, $processorId))
                ->setInputDocuments($inputConfig)
                ->setDocumentOutputConfig($outputConfig)
                ->setSkipHumanReview(true);

            $operation = $client->batchProcessDocuments($request);
            $operationName = (string) $operation->getName();

            if ($operationName === '') {
                throw new DocumentAiProcessingException(
                    'Document AI did not return a batch operation name.',
                    'batch_submit_failed',
                    true,
                );
            }

            return [
                'operation_name' => $operationName,
                'gcs_input_uri' => $inputUri,
                'gcs_output_uri' => $outputUri,
            ];
        } catch (DocumentAiConfigurationException|OcrProviderException $exception) {
            throw $exception;
        } catch (ApiException $exception) {
            throw $this->mapApiException($exception);
        } catch (Throwable $exception) {
            throw new DocumentAiProcessingException(
                'Unable to start batch OCR processing. Please retry or verify Document AI and Cloud Storage configuration.',
                'batch_submit_failed',
                true,
                $exception,
            );
        } finally {
            $client->close();
        }
    }

    /**
     * Poll batch LRO status via plain JSON (avoids protobuf Any / descriptor-pool failures).
     *
     * @return array{done: bool, error: ?string, metadata: array<string, mixed>}
     */
    public function checkOperation(string $operationName): array
    {
        $this->documentAi->validateConfiguration();

        $operationName = trim($operationName);
        if ($operationName === '' || ! str_contains($operationName, '/operations/')) {
            throw new DocumentAiProcessingException(
                'Batch OCR operation name is missing or invalid.',
                'batch_status_failed',
                false,
            );
        }

        try {
            $endpoint = rtrim((string) config('document-ai.api_endpoint'), '/');
            $url = 'https://'.$endpoint.'/v1/'.$operationName;
            $token = $this->accessToken();

            $response = Http::timeout(60)
                ->withToken($token)
                ->acceptJson()
                ->get($url);

            if (! $response->successful()) {
                $status = $response->status();
                $body = Str::lower((string) $response->body());
                if ($status === 404 || str_contains($body, 'not found')) {
                    throw new DocumentAiProcessingException(
                        'Document AI batch operation was not found.',
                        'not_found',
                        false,
                    );
                }
                if ($status === 403 || str_contains($body, 'permission')) {
                    throw new DocumentAiProcessingException(
                        'Document AI or Cloud Storage permission was denied for batch OCR.',
                        'permission_denied',
                        false,
                    );
                }
                if (in_array($status, [429, 500, 502, 503, 504], true)) {
                    throw new DocumentAiProcessingException(
                        'Unable to check batch OCR status. Please retry shortly.',
                        'batch_status_failed',
                        true,
                    );
                }

                throw new DocumentAiProcessingException(
                    'Unable to check batch OCR status. Please retry shortly.',
                    'batch_status_failed',
                    true,
                );
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new DocumentAiProcessingException(
                    'Unable to check batch OCR status. Please retry shortly.',
                    'batch_status_failed',
                    true,
                );
            }

            $done = (bool) ($payload['done'] ?? false);
            if (! $done) {
                return ['done' => false, 'error' => null, 'metadata' => []];
            }

            $error = $payload['error'] ?? null;
            if (is_array($error) && ($error['message'] ?? null)) {
                return [
                    'done' => true,
                    'error' => $this->safeBatchErrorMessage((string) $error['message']),
                    'metadata' => [],
                ];
            }

            return ['done' => true, 'error' => null, 'metadata' => []];
        } catch (DocumentAiConfigurationException|OcrProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DocumentAiProcessingException(
                'Unable to check batch OCR status. Please retry shortly.',
                'batch_status_failed',
                true,
                $exception,
            );
        }
    }

    private function accessToken(): string
    {
        $credentialsPath = $this->documentAi->resolveCredentialsPath();
        if ($credentialsPath === null || ! is_readable($credentialsPath)) {
            throw new DocumentAiConfigurationException(
                'Document AI credentials are not configured.',
            );
        }

        $credentials = new ServiceAccountCredentials(
            ['https://www.googleapis.com/auth/cloud-platform'],
            $credentialsPath,
        );
        $token = $credentials->fetchAuthToken();
        $accessToken = is_array($token) ? (string) ($token['access_token'] ?? '') : '';
        if ($accessToken === '') {
            throw new DocumentAiConfigurationException(
                'Unable to obtain a Google Cloud access token for Document AI.',
            );
        }

        return $accessToken;
    }

    /**
     * Combine every Document AI batch JSON shard and reconcile page coverage.
     *
     * @param  int|null  $expectedPages  PDF page count known at upload (blocks Completed when short)
     * @return array{
     *     text: string,
     *     page_count: int,
     *     languages: list<string>,
     *     pages: list<array<string, mixed>>,
     *     average_confidence: float|null,
     *     result_checksum: string,
     *     shard_count: int,
     *     expected_pages: int|null,
     *     received_pages: int,
     *     unique_pages: int,
     *     missing_pages: list<int>,
     *     duplicate_pages: list<int>,
     *     page_reconciliation_ok: bool
     * }
     */
    public function finalizeFromGcs(string $gcsOutputUri, ?int $expectedPages = null): array
    {
        $parsedUri = $this->storage->parseGsUri(rtrim($gcsOutputUri, '/').'/');
        if ($parsedUri === null) {
            $parsedUri = $this->storage->parseGsUri($gcsOutputUri);
        }

        if ($parsedUri === null) {
            throw new DocumentAiProcessingException(
                'Batch OCR output location is invalid.',
                'batch_output_invalid',
                false,
            );
        }

        $prefix = rtrim($parsedUri['object'], '/');
        $objectNames = array_values(array_unique(array_filter(
            $this->storage->listObjectNames($parsedUri['bucket'], $prefix),
            fn (string $name) => Str::endsWith(strtolower($name), '.json'),
        )));

        if ($objectNames === []) {
            throw new DocumentAiProcessingException(
                'Batch OCR completed but no result JSON files were found in Cloud Storage.',
                'batch_output_missing',
                true,
            );
        }

        usort($objectNames, function (string $a, string $b): int {
            $ra = $this->shardSortKey($a);
            $rb = $this->shardSortKey($b);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            return strnatcasecmp($a, $b);
        });

        $combinedTextParts = [];
        $languages = [];
        $pageMetas = [];
        $confidences = [];
        $shardCount = 0;
        $seenPageNumbers = [];
        $duplicatePages = [];
        $duplicateShards = [];
        $seenShardFingerprints = [];
        $sequentialFallback = 0;

        foreach ($objectNames as $objectName) {
            $raw = $this->storage->downloadObject($parsedUri['bucket'], $objectName);
            $decoded = json_decode($raw, true);
            $shardFingerprint = hash('sha256', $raw);
            unset($raw);

            if (! is_array($decoded)) {
                continue;
            }

            if (isset($seenShardFingerprints[$shardFingerprint])) {
                $duplicateShards[] = basename($objectName);

                continue;
            }
            $seenShardFingerprints[$shardFingerprint] = basename($objectName);

            $shardCount++;
            $text = trim((string) ($decoded['text'] ?? ''));
            if ($text !== '') {
                $combinedTextParts[] = $text;
            }

            $pages = $decoded['pages'] ?? [];
            if (is_array($pages)) {
                foreach ($pages as $index => $page) {
                    $pageLanguages = [];
                    if (is_array($page) && isset($page['detectedLanguages']) && is_array($page['detectedLanguages'])) {
                        foreach ($page['detectedLanguages'] as $lang) {
                            $code = is_array($lang) ? (string) ($lang['languageCode'] ?? '') : '';
                            if ($code !== '') {
                                $pageLanguages[] = $code;
                                $languages[] = $code;
                            }
                        }
                    }

                    $confidence = null;
                    if (is_array($page) && isset($page['layout']['confidence'])) {
                        $confidence = (float) $page['layout']['confidence'];
                        $confidences[] = $confidence;
                    }

                    // Document AI shards reset pageNumber per shard — assign global sequential pages.
                    $sequentialFallback++;
                    $pageNumber = $sequentialFallback;

                    if (isset($seenPageNumbers[$pageNumber])) {
                        $duplicatePages[] = $pageNumber;
                    }
                    $seenPageNumbers[$pageNumber] = true;

                    $paragraphs = $this->extractParagraphsFromJsonPage(
                        is_array($page) ? $page : [],
                        $text,
                    );

                    $pageMetas[] = [
                        'page_number' => $pageNumber,
                        'languages' => array_values(array_unique($pageLanguages)),
                        'paragraph_count' => count($paragraphs),
                        'paragraphs' => $paragraphs,
                        'confidence' => $confidence,
                        'source_object' => basename($objectName),
                        'source_page_index' => is_int($index) ? $index + 1 : null,
                        'has_text' => $paragraphs !== [],
                        'line_count' => count($paragraphs),
                    ];
                }
            }

            unset($decoded);
        }

        if ($duplicateShards !== []) {
            throw new DocumentAiProcessingException(
                'Batch OCR produced duplicate output shards (identical content). Refusing to complete. Duplicates: '
                    .implode(', ', array_slice($duplicateShards, 0, 20)),
                'BATCH_DUPLICATE_SHARDS',
                true,
            );
        }

        if ($duplicatePages !== []) {
            throw new DocumentAiProcessingException(
                'Batch OCR produced duplicate page numbers. Refusing to complete. Pages: '
                    .implode(', ', array_slice(array_values(array_unique($duplicatePages)), 0, 40)),
                'BATCH_DUPLICATE_PAGES',
                true,
            );
        }

        usort($pageMetas, static fn (array $a, array $b) => ($a['page_number'] ?? 0) <=> ($b['page_number'] ?? 0));

        $receivedPages = count($pageMetas);
        $uniquePages = count($seenPageNumbers);
        $pageCount = $uniquePages > 0 ? max(array_keys($seenPageNumbers)) : max(1, count($combinedTextParts));

        $missingPages = [];
        if ($expectedPages !== null && $expectedPages > 0) {
            for ($p = 1; $p <= $expectedPages; $p++) {
                if (! isset($seenPageNumbers[$p])) {
                    $missingPages[] = $p;
                }
            }
        } elseif ($uniquePages > 0) {
            $maxSeen = max(array_keys($seenPageNumbers));
            for ($p = 1; $p <= $maxSeen; $p++) {
                if (! isset($seenPageNumbers[$p])) {
                    $missingPages[] = $p;
                }
            }
        }

        $pageReconciliationOk = $missingPages === []
            && ($expectedPages === null || $expectedPages <= 0 || $uniquePages >= $expectedPages);

        $text = trim(implode("\n\n", $combinedTextParts));
        if ($text === '') {
            throw new DocumentAiProcessingException(
                'No text could be extracted from the batch OCR results.',
                'empty_ocr_response',
                false,
            );
        }

        if (! $pageReconciliationOk) {
            throw new DocumentAiProcessingException(
                'Batch OCR page reconciliation failed. Missing pages: '.implode(', ', array_slice($missingPages, 0, 40))
                    .(count($missingPages) > 40 ? '…' : ''),
                'BATCH_PAGE_RECONCILIATION_FAILED',
                true,
            );
        }

        $checksum = hash('sha256', $text.'|'.$pageCount.'|'.$shardCount.'|'.$uniquePages);

        return [
            'text' => $text,
            'page_count' => $pageCount > 0 ? $pageCount : max(1, count($combinedTextParts)),
            'languages' => array_values(array_unique($languages)),
            'pages' => $pageMetas,
            'average_confidence' => $confidences === []
                ? null
                : round(array_sum($confidences) / count($confidences), 4),
            'result_checksum' => $checksum,
            'shard_count' => $shardCount,
            'expected_pages' => $expectedPages,
            'received_pages' => $receivedPages,
            'unique_pages' => $uniquePages,
            'missing_pages' => $missingPages,
            'duplicate_pages' => array_values(array_unique($duplicatePages)),
            'page_reconciliation_ok' => $pageReconciliationOk,
        ];
    }

    private function shardSortKey(string $objectName): int
    {
        $base = basename($objectName);
        if (preg_match('/(\d+)[-_](\d+)/', $base, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/(\d+)/', $base, $m)) {
            return (int) $m[1];
        }

        return PHP_INT_MAX;
    }

    /**
     * Persist lean paragraphs + normalized bounding boxes so layout directory parsing
     * can run on batch OCR results (not text-only fallback).
     *
     * @param  array<string, mixed>  $page
     * @return list<array{text: string, bounding_box: list<array{x: float, y: float}>, confidence: float|null, x?: float, y?: float}>
     */
    private function extractParagraphsFromJsonPage(array $page, string $fullText): array
    {
        $rawParagraphs = $page['paragraphs'] ?? [];
        if (! is_array($rawParagraphs) || $rawParagraphs === []) {
            return [];
        }

        $out = [];
        foreach ($rawParagraphs as $paragraph) {
            if (! is_array($paragraph)) {
                continue;
            }

            $layout = is_array($paragraph['layout'] ?? null) ? $paragraph['layout'] : [];
            $paragraphText = $this->textFromJsonAnchor($fullText, $layout['textAnchor'] ?? $layout['text_anchor'] ?? null);
            if ($paragraphText === '' && isset($paragraph['text']) && is_string($paragraph['text'])) {
                $paragraphText = trim($paragraph['text']);
            }
            if ($paragraphText === '') {
                continue;
            }

            $vertices = $this->normalizedVerticesFromJsonLayout($layout);
            $xs = array_map(static fn (array $v) => (float) ($v['x'] ?? 0), $vertices);
            $ys = array_map(static fn (array $v) => (float) ($v['y'] ?? 0), $vertices);
            $confidence = isset($layout['confidence']) ? (float) $layout['confidence'] : null;

            $entry = [
                'text' => $paragraphText,
                'bounding_box' => $vertices,
                'confidence' => $confidence,
            ];
            if ($xs !== [] && $ys !== []) {
                $entry['x'] = round(array_sum($xs) / count($xs), 4);
                $entry['y'] = round(array_sum($ys) / count($ys), 4);
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param  mixed  $anchor
     */
    private function textFromJsonAnchor(string $fullText, mixed $anchor): string
    {
        if ($fullText === '' || ! is_array($anchor)) {
            return '';
        }
        $segments = $anchor['textSegments'] ?? $anchor['text_segments'] ?? [];
        if (! is_array($segments) || $segments === []) {
            return '';
        }

        $parts = [];
        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }
            $start = (int) ($segment['startIndex'] ?? $segment['start_index'] ?? 0);
            $end = (int) ($segment['endIndex'] ?? $segment['end_index'] ?? 0);
            if ($end <= $start) {
                continue;
            }
            $parts[] = mb_substr($fullText, $start, $end - $start);
        }

        return trim(implode('', $parts));
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return list<array{x: float, y: float}>
     */
    private function normalizedVerticesFromJsonLayout(array $layout): array
    {
        $poly = $layout['boundingPoly'] ?? $layout['bounding_poly'] ?? null;
        if (! is_array($poly)) {
            return [];
        }
        $raw = $poly['normalizedVertices'] ?? $poly['normalized_vertices'] ?? $poly['vertices'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $vertices = [];
        foreach ($raw as $vertex) {
            if (! is_array($vertex)) {
                continue;
            }
            $vertices[] = [
                'x' => round((float) ($vertex['x'] ?? 0), 4),
                'y' => round((float) ($vertex['y'] ?? 0), 4),
            ];
        }

        return $vertices;
    }

    public function cleanupAfterSuccess(OcrDocument $document): void
    {
        if (config('document-ai.gcs.delete_input_after_success') && $document->gcs_input_uri) {
            $parsed = $this->storage->parseGsUri((string) $document->gcs_input_uri);
            if ($parsed) {
                $this->storage->deleteObject($parsed['bucket'], $parsed['object']);
            }
        }

        if (config('document-ai.gcs.delete_output_after_success') && $document->gcs_output_uri) {
            $parsed = $this->storage->parseGsUri(rtrim((string) $document->gcs_output_uri, '/'));
            if ($parsed) {
                $this->storage->deleteByPrefix($parsed['bucket'], $parsed['object']);
            }
        }
    }

    private function safeBatchErrorMessage(string $message): string
    {
        $lower = Str::lower($message);
        if (str_contains($lower, 'page') && str_contains($lower, 'limit')) {
            return 'This document has too many pages for Document AI batch OCR. Split it into smaller documents.';
        }

        if (str_contains($lower, 'permission') || str_contains($lower, 'denied')) {
            return 'Document AI or Cloud Storage permission was denied for batch OCR.';
        }

        return 'Batch OCR processing failed. Please retry or verify Document AI configuration.';
    }

    private function mapApiException(ApiException $exception): OcrProviderException
    {
        $message = Str::lower($exception->getMessage());

        if (str_contains($message, 'permission') || $exception->getStatus() === 'PERMISSION_DENIED') {
            return new DocumentAiProcessingException(
                'Document AI or Cloud Storage permission was denied for batch OCR.',
                'permission_denied',
                false,
                $exception,
            );
        }

        if (str_contains($message, 'not found') || $exception->getStatus() === 'NOT_FOUND') {
            return new DocumentAiProcessingException(
                'Document AI processor or Cloud Storage bucket was not found for batch OCR.',
                'not_found',
                false,
                $exception,
            );
        }

        return new DocumentAiProcessingException(
            'Unable to run batch OCR. Please retry or verify Document AI and Cloud Storage configuration.',
            'batch_failed',
            true,
            $exception,
        );
    }
}
