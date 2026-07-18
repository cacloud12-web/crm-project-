<?php

namespace App\Services\DocumentAi;

use App\Exceptions\DocumentAi\DocumentAiConfigurationException;
use App\Exceptions\DocumentAi\DocumentAiProcessingException;
use App\Exceptions\Ocr\OcrAuthenticationException;
use App\Exceptions\Ocr\OcrFileException;
use App\Exceptions\Ocr\OcrPermissionException;
use App\Exceptions\Ocr\OcrProcessorNotFoundException;
use App\Exceptions\Ocr\OcrProviderException;
use App\Support\DocumentAi\LayoutGeometryHelper;
use App\Support\DocumentAi\TextAnchorHelper;
use Google\ApiCore\ApiException;
use Google\Cloud\DocumentAI\V1\Client\DocumentProcessorServiceClient;
use Google\Cloud\DocumentAI\V1\ProcessRequest;
use Google\Cloud\DocumentAI\V1\RawDocument;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class GoogleDocumentAiService
{
    public function resolveCredentialsPath(): ?string
    {
        $configured = trim((string) config('document-ai.credentials', ''));
        $candidates = array_filter([
            $this->readablePath(getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: null),
            $configured !== '' && str_starts_with($configured, DIRECTORY_SEPARATOR) ? $configured : null,
            $configured !== '' ? base_path($configured) : null,
            storage_path('app/google/document-ai-service-account.json'),
        ]);

        foreach (array_unique($candidates) as $path) {
            if ($this->readablePath($path) !== null) {
                return $path;
            }
        }

        return null;
    }

    public function usesApplicationDefaultCredentials(): bool
    {
        if ($this->resolveCredentialsPath() !== null) {
            return false;
        }

        $adc = getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: '';
        if (is_string($adc) && $adc !== '' && is_readable($adc)) {
            return true;
        }

        $homeAdc = (getenv('HOME') ?: '').'/.config/gcloud/application_default_credentials.json';

        return is_readable($homeAdc);
    }

    public function validateConfiguration(): void
    {
        $this->validateProjectId();
        $this->validateProcessorId();
        $this->validateLocation();

        $credentialsPath = $this->resolveCredentialsPath();
        if ($credentialsPath === null) {
            throw new DocumentAiConfigurationException(
                'Document AI credentials file is missing or unreadable. Place the service-account JSON at storage/app/google/document-ai-service-account.json and ensure GOOGLE_DOCUMENT_AI_CREDENTIALS points to it.',
            );
        }

        $this->validateCredentialsFile($credentialsPath);
    }

    public function processBinary(string $binary, string $mimeType): array
    {
        if ($binary === '') {
            throw new OcrFileException('The uploaded document is empty.', 'empty_file');
        }

        if (! in_array($mimeType, config('document-ai.supported_mime_types', []), true)) {
            throw new OcrFileException('Unsupported document type.', 'unsupported_mime');
        }

        $this->validateConfiguration();

        $projectId = $this->validateProjectId();
        $location = $this->validateLocation();
        $processorId = $this->validateProcessorId();
        $credentialsPath = $this->resolveCredentialsPath();
        $endpoint = (string) config('document-ai.api_endpoint')
            ?: sprintf('%s-documentai.googleapis.com', $location);
        $timeout = (int) config('document-ai.timeout', 120);

        $clientConfig = [
            'apiEndpoint' => $endpoint,
            'transportConfig' => [
                'grpc' => [
                    'timeout' => $timeout * 1000,
                ],
            ],
        ];

        if ($credentialsPath !== null) {
            $clientConfig['credentials'] = $credentialsPath;
        }

        $client = new DocumentProcessorServiceClient($clientConfig);

        try {
            $processorName = $client->processorName($projectId, $location, $processorId);

            $rawDocument = (new RawDocument)
                ->setContent($binary)
                ->setMimeType($mimeType);

            $request = (new ProcessRequest)
                ->setName($processorName)
                ->setRawDocument($rawDocument);

            $response = $client->processDocument($request);
            $document = $response->getDocument();
            $text = trim((string) $document->getText());

            if ($text === '') {
                throw new DocumentAiProcessingException('No text could be extracted from the document.', 'empty_ocr_response', false);
            }

            return $this->normalizeDocument($document, $text, $processorName);
        } catch (DocumentAiConfigurationException|OcrProviderException $exception) {
            throw $exception;
        } catch (ApiException $exception) {
            throw $this->mapApiException($exception);
        } catch (Throwable $exception) {
            throw new DocumentAiProcessingException(
                'The document could not be processed. Please verify the OCR configuration or retry.',
                'processing_failed',
                true,
                $exception,
            );
        } finally {
            if (isset($client)) {
                $client->close();
            }
        }
    }

    private function normalizeDocument(object $document, string $text, string $processorName): array
    {
        $pages = [];
        $confidences = [];
        $detectedLanguages = [];
        $pageTextChunks = [];
        $tables = [];
        $entities = [];

        foreach ($document->getPages() as $index => $page) {
            $pageLanguages = [];
            foreach ($page->getDetectedLanguages() as $language) {
                $code = (string) $language->getLanguageCode();
                if ($code !== '') {
                    $pageLanguages[] = $code;
                    $detectedLanguages[] = $code;
                }
            }

            $paragraphs = [];
            $paragraphLayouts = [];
            foreach ($page->getParagraphs() as $paragraph) {
                $layout = $paragraph->getLayout();
                $paragraphText = TextAnchorHelper::extract($text, $layout?->getTextAnchor());
                if ($paragraphText === '') {
                    continue;
                }

                $geometry = LayoutGeometryHelper::fromLayout($layout);
                $x = null;
                $y = null;
                try {
                    $vertices = $layout?->getBoundingPoly()?->getNormalizedVertices();
                    if ($vertices) {
                        $xs = [];
                        $ys = [];
                        foreach ($vertices as $vertex) {
                            $xs[] = (float) $vertex->getX();
                            $ys[] = (float) $vertex->getY();
                        }
                        if ($xs !== [] && $ys !== []) {
                            $x = round(array_sum($xs) / count($xs), 4);
                            $y = round(array_sum($ys) / count($ys), 4);
                        }
                    }
                } catch (\Throwable) {
                    // Bounding boxes are optional; text-only paragraphs still help structuring.
                }

                $entry = [
                    'text' => $paragraphText,
                    'bounding_box' => $geometry['vertices'] ?? [],
                    'confidence' => $geometry['confidence'] ?? null,
                ];
                if ($x !== null) {
                    $entry['x'] = $x;
                }
                if ($y !== null) {
                    $entry['y'] = $y;
                }
                $paragraphs[] = $entry;
                $paragraphLayouts[] = $entry;
            }

            $pageNumber = $index + 1;
            $pageBody = trim(implode("\n", array_map(
                static fn ($p) => is_array($p) ? (string) ($p['text'] ?? '') : (string) $p,
                $paragraphs,
            )));
            if ($pageBody !== '') {
                $pageTextChunks[] = "--- Page {$pageNumber} ---\n".$pageBody;
            }

            $pageConfidence = $page->getLayout()?->getConfidence();
            if ($pageConfidence !== null) {
                $confidences[] = (float) $pageConfidence;
            }

            $pageTables = $this->extractPageTables($page, $text, $pageNumber);
            foreach ($pageTables as $table) {
                $tables[] = $table;
            }

            $pages[] = [
                'page_number' => $pageNumber,
                'languages' => array_values(array_unique($pageLanguages)),
                'paragraph_count' => count($paragraphs),
                'paragraphs' => $paragraphs,
                'paragraph_layouts' => $paragraphLayouts,
                'confidence' => $pageConfidence !== null ? (float) $pageConfidence : null,
                'line_count' => count($paragraphs),
                'table_count' => count($pageTables),
                'has_text' => $pageBody !== '',
                'text_length' => mb_strlen($pageBody),
                'tables' => $pageTables,
            ];
        }

        try {
            if (method_exists($document, 'getEntities')) {
                foreach ($document->getEntities() as $entity) {
                    $type = method_exists($entity, 'getType') ? (string) $entity->getType() : '';
                    $mention = method_exists($entity, 'getMentionText') ? (string) $entity->getMentionText() : '';
                    $conf = method_exists($entity, 'getConfidence') && $entity->getConfidence() !== null
                        ? round((float) $entity->getConfidence(), 4)
                        : null;
                    if ($type === '' && $mention === '') {
                        continue;
                    }
                    $entities[] = [
                        'type' => $type,
                        'mention_text' => $mention,
                        'confidence' => $conf,
                        'page_anchor' => $this->entityPageAnchor($entity),
                    ];
                }
            }
        } catch (\Throwable) {
            $entities = [];
        }

        $averageConfidence = $confidences !== []
            ? round(array_sum($confidences) / count($confidences), 4)
            : null;

        $stitched = trim(implode("\n\n", $pageTextChunks));
        $finalText = $stitched !== '' ? $stitched : $text;

        return [
            'provider' => 'google_document_ai',
            'processing_mode' => 'online',
            'text' => $finalText,
            'page_count' => count($pages),
            'confidence' => $averageConfidence,
            'languages' => array_values(array_unique($detectedLanguages)),
            'detected_languages' => array_values(array_unique($detectedLanguages)),
            'pages' => $pages,
            'entities' => $entities,
            'tables' => $tables,
            'structured_data' => [
                'pages' => $pages,
                'tables' => $tables,
                'entities' => $entities,
                'languages' => array_values(array_unique($detectedLanguages)),
                'extraction_mode' => $tables !== [] ? 'document_ai_tables_and_paragraphs' : 'document_ai_paragraphs',
            ],
            'raw_response' => [
                'processor_name' => $processorName,
                'page_count' => count($pages),
                'table_count' => count($tables),
                'entity_count' => count($entities),
                'language_count' => count(array_unique($detectedLanguages)),
            ],
            'average_confidence' => $averageConfidence,
            'processor_name' => $processorName,
            'provider_reference' => $processorName,
            'metadata' => [
                'provider' => 'google_document_ai',
                'page_count' => count($pages),
                'table_count' => count($tables),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractPageTables(object $page, string $fullText, int $pageNumber): array
    {
        $tables = [];
        try {
            if (! method_exists($page, 'getTables')) {
                return [];
            }
            foreach ($page->getTables() as $tableIndex => $table) {
                $headerRows = [];
                $bodyRows = [];
                if (method_exists($table, 'getHeaderRows')) {
                    foreach ($table->getHeaderRows() as $row) {
                        $headerRows[] = $this->extractTableRow($row, $fullText);
                    }
                }
                if (method_exists($table, 'getBodyRows')) {
                    foreach ($table->getBodyRows() as $row) {
                        $bodyRows[] = $this->extractTableRow($row, $fullText);
                    }
                }
                $layout = method_exists($table, 'getLayout') ? $table->getLayout() : null;
                $geometry = LayoutGeometryHelper::fromLayout($layout);
                $tables[] = [
                    'page_number' => $pageNumber,
                    'table_index' => $tableIndex,
                    'header_rows' => $headerRows,
                    'body_rows' => $bodyRows,
                    'row_count' => count($headerRows) + count($bodyRows),
                    'bounding_box' => $geometry['vertices'] ?? [],
                    'confidence' => $geometry['confidence'] ?? null,
                ];
            }
        } catch (\Throwable) {
            return [];
        }

        return $tables;
    }

    /**
     * @return list<array{text: string, confidence: float|null, bounding_box: list<array{x: float, y: float}>, row_span: int, col_span: int}>
     */
    private function extractTableRow(object $row, string $fullText): array
    {
        $cells = [];
        if (! method_exists($row, 'getCells')) {
            return $cells;
        }
        foreach ($row->getCells() as $cell) {
            $layout = method_exists($cell, 'getLayout') ? $cell->getLayout() : null;
            $cellText = TextAnchorHelper::extract($fullText, $layout?->getTextAnchor());
            $geometry = LayoutGeometryHelper::fromLayout($layout);
            $rowSpan = method_exists($cell, 'getRowSpan') ? (int) $cell->getRowSpan() : 1;
            $colSpan = method_exists($cell, 'getColSpan') ? (int) $cell->getColSpan() : 1;
            $cells[] = [
                'text' => $cellText,
                'confidence' => $geometry['confidence'] ?? null,
                'bounding_box' => $geometry['vertices'] ?? [],
                'row_span' => max(1, $rowSpan),
                'col_span' => max(1, $colSpan),
            ];
        }

        return $cells;
    }

    /**
     * @return list<int>
     */
    private function entityPageAnchor(object $entity): array
    {
        $pages = [];
        try {
            if (! method_exists($entity, 'getPageAnchor')) {
                return [];
            }
            $anchor = $entity->getPageAnchor();
            if (! $anchor || ! method_exists($anchor, 'getPageRefs')) {
                return [];
            }
            foreach ($anchor->getPageRefs() as $ref) {
                if (method_exists($ref, 'getPage') && $ref->getPage() !== null) {
                    $pages[] = ((int) $ref->getPage()) + 1;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_unique($pages));
    }

    private function validateProjectId(): string
    {
        $projectId = trim((string) config('document-ai.project_id', ''));
        if ($projectId === '') {
            throw new DocumentAiConfigurationException('Google Document AI project ID is not configured.');
        }

        return $projectId;
    }

    private function validateProcessorId(): string
    {
        $processorId = trim((string) config('document-ai.processor_id', ''));
        if ($processorId === '') {
            throw new DocumentAiConfigurationException('Google Document AI processor ID is not configured.');
        }

        return $processorId;
    }

    private function validateLocation(): string
    {
        $location = strtolower(trim((string) config('document-ai.location', 'us')));
        $allowed = config('document-ai.allowed_locations', ['us', 'eu']);

        if (! in_array($location, $allowed, true)) {
            throw new DocumentAiConfigurationException('Google Document AI processor location is not supported.');
        }

        return $location;
    }

    private function validateCredentialsFile(string $path): void
    {
        if (! is_readable($path)) {
            throw new DocumentAiConfigurationException;
        }

        $contents = File::get($path);
        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new DocumentAiConfigurationException;
        }

        foreach (['type', 'client_email', 'private_key', 'project_id'] as $field) {
            if (empty($decoded[$field])) {
                throw new DocumentAiConfigurationException;
            }
        }

        if (($decoded['type'] ?? '') !== 'service_account') {
            throw new DocumentAiConfigurationException;
        }
    }

    private function readablePath(mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        return is_readable($path) ? $path : null;
    }

    private function mapApiException(ApiException $exception): OcrProviderException
    {
        $message = Str::lower($exception->getMessage());
        $status = $exception->getStatus();

        if (str_contains($message, 'unauthenticated') || $status === 'UNAUTHENTICATED') {
            return new OcrAuthenticationException(previous: $exception);
        }

        if (str_contains($message, 'permission denied') || $status === 'PERMISSION_DENIED') {
            return new OcrPermissionException(previous: $exception);
        }

        if (str_contains($message, 'not found') || $status === 'NOT_FOUND') {
            return new OcrProcessorNotFoundException(previous: $exception);
        }

        if (str_contains($message, 'billing') || str_contains($message, 'billing disabled')) {
            return new DocumentAiProcessingException(
                'The document could not be processed. Please verify the OCR configuration or retry.',
                'billing_disabled',
                false,
                $exception,
            );
        }

        if (str_contains($message, 'quota') || str_contains($message, 'rate limit') || $status === 'RESOURCE_EXHAUSTED') {
            return new DocumentAiProcessingException(
                'The document could not be processed right now. Please retry in a few minutes.',
                'rate_limited',
                true,
                $exception,
            );
        }

        if (str_contains($message, 'deadline') || str_contains($message, 'timeout') || $status === 'DEADLINE_EXCEEDED') {
            return new DocumentAiProcessingException(
                'The document could not be processed right now. Please retry in a few minutes.',
                'timeout',
                true,
                $exception,
            );
        }

        if (str_contains($message, 'unavailable') || $status === 'UNAVAILABLE') {
            return new DocumentAiProcessingException(
                'The document could not be processed right now. Please retry in a few minutes.',
                'service_unavailable',
                true,
                $exception,
            );
        }

        if (
            str_contains($message, 'page_limit_exceeded')
            || str_contains($message, 'page limit')
            || str_contains($message, 'pages exceed')
            || str_contains($message, 'document pages exceed')
        ) {
            $pageLimit = $this->extractApiMetadataValue($exception, 'page_limit') ?? '30';
            $pages = $this->extractApiMetadataValue($exception, 'pages');
            $detail = $pages !== null
                ? " This PDF has {$pages} pages (online OCR limit is {$pageLimit})."
                : " Online OCR supports up to {$pageLimit} pages per document.";

            return new DocumentAiProcessingException(
                'This document has too many pages for online OCR.'.$detail
                .' Split it into smaller PDFs and upload again.',
                'page_limit_exceeded',
                false,
                $exception,
            );
        }

        return new DocumentAiProcessingException(
            'The document could not be processed. Please verify the OCR configuration or retry.',
            'processing_failed',
            true,
            $exception,
        );
    }

    private function extractApiMetadataValue(ApiException $exception, string $key): ?string
    {
        $raw = $exception->getMessage();
        if (preg_match('/"'.preg_quote($key, '/').'"\s*:\s*"([^"]+)"/', $raw, $matches)) {
            return $matches[1];
        }

        return null;
    }
}