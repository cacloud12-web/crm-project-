<?php

namespace App\Services\DocumentAi;

use App\Exceptions\DocumentAi\DocumentAiConfigurationException;
use App\Exceptions\DocumentAi\DocumentAiProcessingException;
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
        return $this->resolveCredentialsPath() === null;
    }

    public function validateConfiguration(): void
    {
        $credentialsPath = $this->resolveCredentialsPath();
        if ($credentialsPath !== null) {
            $this->validateCredentialsFile($credentialsPath);
        }

        $this->validateProjectId();
        $this->validateProcessorId();
        $this->validateLocation();
    }

    public function processBinary(string $binary, string $mimeType): array
    {
        if ($binary === '') {
            throw new DocumentAiProcessingException('The uploaded document is empty.', 'empty_file', false);
        }

        if (! in_array($mimeType, config('document-ai.supported_mime_types', []), true)) {
            throw new DocumentAiProcessingException('Unsupported document type.', 'unsupported_mime', false);
        }

        $this->validateConfiguration();

        $projectId = $this->validateProjectId();
        $location = $this->validateLocation();
        $processorId = $this->validateProcessorId();
        $credentialsPath = $this->resolveCredentialsPath();
        $endpoint = sprintf('%s-documentai.googleapis.com', $location);
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
        } catch (DocumentAiConfigurationException|DocumentAiProcessingException $exception) {
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
            foreach ($page->getParagraphs() as $paragraph) {
                $paragraphText = TextAnchorHelper::extract($text, $paragraph->getLayout()?->getTextAnchor());
                if ($paragraphText !== '') {
                    $paragraphs[] = $paragraphText;
                }
            }

            $pageConfidence = $page->getLayout()?->getConfidence();
            if ($pageConfidence !== null) {
                $confidences[] = (float) $pageConfidence;
            }

            $pages[] = [
                'page_number' => $index + 1,
                'languages' => array_values(array_unique($pageLanguages)),
                'paragraph_count' => count($paragraphs),
            ];
        }

        $averageConfidence = $confidences !== []
            ? round(array_sum($confidences) / count($confidences), 4)
            : null;

        return [
            'text' => $text,
            'page_count' => count($pages),
            'detected_languages' => array_values(array_unique($detectedLanguages)),
            'pages' => $pages,
            'entities' => [],
            'average_confidence' => $averageConfidence,
            'processor_name' => $processorName,
        ];
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

    private function mapApiException(ApiException $exception): DocumentAiProcessingException
    {
        $message = Str::lower($exception->getMessage());
        $status = $exception->getStatus();

        if (str_contains($message, 'permission denied') || $status === 'PERMISSION_DENIED') {
            return new DocumentAiProcessingException(
                'The document could not be processed. Please verify the OCR configuration or retry.',
                'permission_denied',
                false,
                $exception,
            );
        }

        if (str_contains($message, 'not found') || $status === 'NOT_FOUND') {
            return new DocumentAiProcessingException(
                'The document could not be processed. Please verify the OCR configuration or retry.',
                'processor_not_found',
                false,
                $exception,
            );
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

        return new DocumentAiProcessingException(
            'The document could not be processed. Please verify the OCR configuration or retry.',
            'processing_failed',
            true,
            $exception,
        );
    }
}
