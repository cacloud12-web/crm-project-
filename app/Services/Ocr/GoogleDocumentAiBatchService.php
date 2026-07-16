<?php

namespace App\Services\Ocr;

use App\Exceptions\DocumentAi\DocumentAiConfigurationException;
use App\Exceptions\DocumentAi\DocumentAiProcessingException;
use App\Exceptions\Ocr\OcrProviderException;
use App\Models\OcrDocument;
use App\Services\DocumentAi\GoogleDocumentAiService;
use Google\ApiCore\ApiException;
use Google\Cloud\DocumentAI\V1\BatchDocumentsInputConfig;
use Google\Cloud\DocumentAI\V1\BatchProcessRequest;
use Google\Cloud\DocumentAI\V1\Client\DocumentProcessorServiceClient;
use Google\Cloud\DocumentAI\V1\DocumentOutputConfig;
use Google\Cloud\DocumentAI\V1\DocumentOutputConfig\GcsOutputConfig;
use Google\Cloud\DocumentAI\V1\GcsDocument;
use Google\Cloud\DocumentAI\V1\GcsDocuments;
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
     * @return array{done: bool, error: ?string, metadata: array<string, mixed>}
     */
    public function checkOperation(string $operationName): array
    {
        $this->documentAi->validateConfiguration();
        $credentialsPath = $this->documentAi->resolveCredentialsPath();
        $endpoint = (string) config('document-ai.api_endpoint');

        $clientConfig = ['apiEndpoint' => $endpoint];
        if ($credentialsPath !== null) {
            $clientConfig['credentials'] = $credentialsPath;
        }

        $client = new DocumentProcessorServiceClient($clientConfig);

        try {
            $operation = $client->resumeOperation($operationName, 'batchProcessDocuments');
            if (! $operation->isDone()) {
                return [
                    'done' => false,
                    'error' => null,
                    'metadata' => [],
                ];
            }

            if ($operation->operationSucceeded()) {
                return [
                    'done' => true,
                    'error' => null,
                    'metadata' => [],
                ];
            }

            $error = $operation->getError();
            $message = $error
                ? (string) ($error->getMessage() ?: 'Batch OCR operation failed.')
                : 'Batch OCR operation failed.';

            return [
                'done' => true,
                'error' => $this->safeBatchErrorMessage($message),
                'metadata' => [],
            ];
        } catch (ApiException $exception) {
            throw $this->mapApiException($exception);
        } catch (Throwable $exception) {
            throw new DocumentAiProcessingException(
                'Unable to check batch OCR status. Please retry shortly.',
                'batch_status_failed',
                true,
                $exception,
            );
        } finally {
            $client->close();
        }
    }

    /**
     * Parse batch Document JSON outputs incrementally and combine text in page order.
     *
     * @return array{
     *     text: string,
     *     page_count: int,
     *     languages: list<string>,
     *     pages: list<array<string, mixed>>,
     *     average_confidence: float|null,
     *     result_checksum: string,
     *     shard_count: int
     * }
     */
    public function finalizeFromGcs(string $gcsOutputUri): array
    {
        $parsedUri = $this->storage->parseGsUri(rtrim($gcsOutputUri, '/').'/');
        if ($parsedUri === null) {
            // Accept gs://bucket/prefix without trailing slash.
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
        $objectNames = array_values(array_filter(
            $this->storage->listObjectNames($parsedUri['bucket'], $prefix),
            fn (string $name) => Str::endsWith(strtolower($name), '.json'),
        ));

        if ($objectNames === []) {
            throw new DocumentAiProcessingException(
                'Batch OCR completed but no result JSON files were found in Cloud Storage.',
                'batch_output_missing',
                true,
            );
        }

        natcasesort($objectNames);
        $objectNames = array_values($objectNames);

        $combinedTextParts = [];
        $languages = [];
        $pageMetas = [];
        $confidences = [];
        $pageCount = 0;
        $shardCount = 0;

        foreach ($objectNames as $objectName) {
            $raw = $this->storage->downloadObject($parsedUri['bucket'], $objectName);
            $decoded = json_decode($raw, true);
            unset($raw);

            if (! is_array($decoded)) {
                continue;
            }

            $shardCount++;
            $text = trim((string) ($decoded['text'] ?? ''));
            if ($text !== '') {
                $combinedTextParts[] = $text;
            }

            $pages = $decoded['pages'] ?? [];
            if (is_array($pages)) {
                foreach ($pages as $index => $page) {
                    $pageCount++;
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

                    $pageMetas[] = [
                        'page_number' => $pageCount,
                        'languages' => array_values(array_unique($pageLanguages)),
                        'paragraph_count' => is_array($page['paragraphs'] ?? null) ? count($page['paragraphs']) : 0,
                        'confidence' => $confidence,
                        'source_object' => basename($objectName),
                        'source_page_index' => is_int($index) ? $index + 1 : null,
                    ];
                }
            }

            unset($decoded);
        }

        $text = trim(implode("\n\n", $combinedTextParts));
        if ($text === '') {
            throw new DocumentAiProcessingException(
                'No text could be extracted from the batch OCR results.',
                'empty_ocr_response',
                false,
            );
        }

        $checksum = hash('sha256', $text.'|'.$pageCount.'|'.$shardCount);

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
        ];
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
