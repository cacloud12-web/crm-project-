<?php

namespace App\Contracts\Ocr;

interface OcrProcessorInterface
{
    /**
     * Process raw document binary with Google Document AI (or future providers).
     *
     * @return array{
     *     text: string,
     *     page_count: int|null,
     *     languages: list<string>,
     *     detected_languages: list<string>,
     *     pages: list<array<string, mixed>>,
     *     entities: list<mixed>,
     *     average_confidence: float|null,
     *     processor_name: string|null,
     *     provider_reference: string|null,
     *     metadata: array<string, mixed>
     * }
     */
    public function processBinary(string $binary, string $mimeType): array;

    public function validateConfiguration(): void;

    public function resolveCredentialsPath(): ?string;
}
