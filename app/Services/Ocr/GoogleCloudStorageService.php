<?php

namespace App\Services\Ocr;

use App\Exceptions\DocumentAi\DocumentAiConfigurationException;
use App\Exceptions\Ocr\OcrProviderException;
use App\Services\DocumentAi\GoogleDocumentAiService;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Support\Str;
use Throwable;

class GoogleCloudStorageService
{
    public function __construct(
        private readonly GoogleDocumentAiService $documentAi,
        private readonly OcrProcessingModeSelector $modeSelector,
    ) {}

    public function validateConfiguration(): void
    {
        $this->modeSelector->assertBatchConfigured();
        $this->documentAi->validateConfiguration();
    }

    public function uploadObject(string $bucketName, string $objectName, string $binary, string $contentType): string
    {
        $this->validateConfiguration();
        $bucketName = $this->modeSelector->normalizedBucket($bucketName);

        try {
            $bucket = $this->bucket($bucketName);
            $bucket->upload($binary, [
                'name' => ltrim($objectName, '/'),
                'metadata' => [
                    'contentType' => $contentType,
                ],
            ]);
        } catch (DocumentAiConfigurationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new OcrProviderException(
                'The server could not upload the document to Cloud Storage. Please contact the administrator.',
                'gcs_upload_failed',
                true,
                0,
                $exception,
            );
        }

        return 'gs://'.$bucketName.'/'.ltrim($objectName, '/');
    }

    /**
     * @return list<string> Object names (not full URIs)
     */
    public function listObjectNames(string $bucketName, string $prefix): array
    {
        $this->validateConfiguration();
        $bucketName = $this->modeSelector->normalizedBucket($bucketName);
        $names = [];

        foreach ($this->bucket($bucketName)->objects(['prefix' => ltrim($prefix, '/')]) as $object) {
            $name = (string) $object->name();
            if ($name !== '') {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    public function downloadObject(string $bucketName, string $objectName): string
    {
        $this->validateConfiguration();
        $bucketName = $this->modeSelector->normalizedBucket($bucketName);

        try {
            return $this->bucket($bucketName)->object($objectName)->downloadAsString();
        } catch (Throwable $exception) {
            throw new OcrProviderException(
                'The server could not download OCR result objects from Cloud Storage.',
                'gcs_download_failed',
                true,
                0,
                $exception,
            );
        }
    }

    public function objectExists(string $bucketName, string $objectName): bool
    {
        try {
            return $this->bucket($this->modeSelector->normalizedBucket($bucketName))
                ->object($objectName)
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public function deleteObject(string $bucketName, string $objectName): void
    {
        try {
            $object = $this->bucket($this->modeSelector->normalizedBucket($bucketName))->object($objectName);
            if ($object->exists()) {
                $object->delete();
            }
        } catch (Throwable) {
            // Best-effort cleanup only.
        }
    }

    public function deleteByPrefix(string $bucketName, string $prefix): void
    {
        foreach ($this->listObjectNames($bucketName, $prefix) as $name) {
            $this->deleteObject($bucketName, $name);
        }
    }

    /**
     * @return array{bucket: string, object: string}|null
     */
    public function parseGsUri(string $uri): ?array
    {
        if (! preg_match('#^gs://([^/]+)/(.+)$#', $uri, $matches)) {
            return null;
        }

        return [
            'bucket' => $matches[1],
            'object' => $matches[2],
        ];
    }

    /**
     * ocr-input/{year}/{month}/{ocr_document_uuid}/original.{ext}
     */
    public function buildInputObjectPath(int $ocrDocumentId, string $extension): string
    {
        $prefix = (string) config('document-ai.gcs.input_prefix', 'ocr-input');
        $extension = strtolower(preg_replace('/[^a-z0-9]/i', '', $extension) ?: 'pdf');
        $folder = (string) Str::uuid();

        return trim($prefix.'/'.now()->format('Y/m').'/'.$folder.'/original.'.$extension, '/');
    }

    /**
     * ocr-output/{ocr_document_uuid}/
     */
    public function buildOutputPrefix(int $ocrDocumentId): string
    {
        $prefix = (string) config('document-ai.gcs.output_prefix', 'ocr-output');
        $folder = (string) Str::uuid();

        return trim($prefix.'/'.$folder, '/').'/';
    }

    private function bucket(string $bucketName): Bucket
    {
        return $this->client()->bucket($bucketName);
    }

    private function client(): StorageClient
    {
        $credentials = $this->documentAi->resolveCredentialsPath();
        if ($credentials === null) {
            throw new DocumentAiConfigurationException(
                OcrProcessingModeSelector::USER_BATCH_CONFIG_MESSAGE,
                'Document AI / GCS credentials file is missing. Configure GOOGLE_DOCUMENT_AI_CREDENTIALS or GOOGLE_APPLICATION_CREDENTIALS.',
            );
        }

        return new StorageClient([
            'projectId' => (string) config('document-ai.project_id'),
            'keyFilePath' => $credentials,
        ]);
    }
}
