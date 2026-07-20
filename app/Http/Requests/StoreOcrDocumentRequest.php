<?php

namespace App\Http\Requests;

use App\Models\CaMaster;
use App\Services\Rbac\EmployeeDataScopeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

class StoreOcrDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('create', \App\Models\OcrDocument::class);
    }

    public function rules(): array
    {
        $maxKb = max(1, (int) config('document-ai.max_file_mb', 20)) * 1024;
        $mimes = implode(',', config('document-ai.supported_mime_types', [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/tiff',
        ]));
        $extensions = implode(',', config('document-ai.supported_extensions', [
            'pdf', 'jpg', 'jpeg', 'png', 'tif', 'tiff',
        ]));

        return [
            // Optional: omit for library/OCR Import (future CA mapping phase attaches later).
            'ca_id' => ['nullable', 'integer', 'exists:ca_masters,ca_id'],
            // UI requires a selection; omitted values default to sales_team in the controller for lead-drawer/API callers.
            'import_type' => ['nullable', 'string', 'in:master_ca,sales_team'],
            'force_reimport' => ['nullable', 'boolean'],
            'document' => [
                'required',
                'file',
                'max:'.$maxKb,
                'mimetypes:'.$mimes.',application/x-pdf',
                'mimes:'.$extensions,
            ],
        ];
    }

    public function messages(): array
    {
        $maxMb = max(1, (int) config('document-ai.max_file_mb', 20));

        return [
            'import_type.required' => 'Select Import Type: Master CA Data or Sales Team Data before uploading.',
            'import_type.in' => 'Import Type must be Master CA Data or Sales Team Data.',
            'document.required' => 'Please choose a PDF or image document to upload.',
            'document.file' => $this->uploadedErrorMessage(),
            'document.max' => "The document may not be larger than {$maxMb} MB.",
            'document.mimetypes' => 'The document must be a PDF, JPG, PNG, or TIFF file.',
            'document.mimes' => 'The document must be a PDF, JPG, PNG, or TIFF file.',
            'document.uploaded' => $this->uploadedErrorMessage(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $uploadError = $this->phpUploadErrorMessage();
            if ($uploadError !== null) {
                $validator->errors()->add('document', $uploadError);

                return;
            }

            $caId = (int) $this->input('ca_id');
            if ($caId > 0) {
                try {
                    app(EmployeeDataScopeService::class)->ensureCanAccessCaMaster($caId);
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                    $validator->errors()->add('ca_id', 'You do not have access to this firm.');
                }

                if (! CaMaster::query()->whereKey($caId)->exists()) {
                    $validator->errors()->add('ca_id', 'The selected firm could not be found.');
                }
            }

            $file = $this->file('document');
            if ($file instanceof UploadedFile) {
                if ($file->getSize() === 0) {
                    $validator->errors()->add('document', 'The uploaded document is empty.');
                }

                $detected = (string) ($file->getMimeType() ?: '');
                $allowed = array_merge(
                    config('document-ai.supported_mime_types', []),
                    ['application/x-pdf'],
                );
                if ($detected !== '' && ! in_array($detected, $allowed, true)) {
                    $validator->errors()->add('document', 'The document must be a PDF, JPG, PNG, or TIFF file.');
                }
            } elseif (! $this->hasFile('document')) {
                $contentLength = (int) $this->server('CONTENT_LENGTH', 0);
                $postMax = $this->iniBytes((string) ini_get('post_max_size'));
                if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax) {
                    $validator->errors()->add(
                        'document',
                        'The file exceeds the server upload limit (post_max_size). Ask your host to raise it above '
                        .config('document-ai.max_file_mb', 20).' MB.',
                    );
                }
            }
        });
    }

    private function uploadedErrorMessage(): string
    {
        return $this->phpUploadErrorMessage()
            ?? 'The document failed to upload. Please refresh the page and try again.';
    }

    private function phpUploadErrorMessage(): ?string
    {
        $file = $this->file('document');
        if (! $file instanceof UploadedFile) {
            $raw = $this->files->get('document');
            if ($raw instanceof UploadedFile) {
                $file = $raw;
            }
        }

        if (! $file instanceof UploadedFile) {
            return null;
        }

        $appMaxMb = max(1, (int) config('document-ai.max_file_mb', 20));

        return match ($file->getError()) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE => 'The file exceeds the server upload limit (upload_max_filesize='
                .ini_get('upload_max_filesize')
                ."). Raise PHP upload_max_filesize/post_max_size above {$appMaxMb} MB, then restart "
                .'`php -c php-local.ini artisan serve` (or your PHP-FPM pool), then retry.',
            UPLOAD_ERR_FORM_SIZE => "The document may not be larger than {$appMaxMb} MB.",
            UPLOAD_ERR_PARTIAL => 'The document was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Please choose a PDF or image document to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server could not save the uploaded file (missing temporary folder).',
            UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded file.',
            UPLOAD_ERR_EXTENSION => 'A server extension blocked this upload.',
            default => 'The document failed to upload. Please try again.',
        };
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return (int) match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
