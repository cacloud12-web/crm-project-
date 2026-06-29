<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class ValidBulkImportFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The uploaded file is invalid or corrupted.');

            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());
        $allowed = ['csv', 'txt', 'xlsx'];

        if (! in_array($extension, $allowed, true)) {
            $fail('Only CSV and Excel (.xlsx) files are supported.');

            return;
        }

        $mime = strtolower((string) $value->getMimeType());
        $allowedMimes = [
            'text/csv',
            'text/plain',
            'application/csv',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/octet-stream',
        ];

        if (! in_array($mime, $allowedMimes, true)) {
            $fail('The file type is not allowed for bulk import.');

            return;
        }

        if ($extension === 'xlsx') {
            $handle = fopen($value->getRealPath(), 'rb');
            if ($handle === false) {
                $fail('Unable to read the uploaded file.');

                return;
            }

            $header = fread($handle, 2);
            fclose($handle);

            if ($header !== 'PK') {
                $fail('The Excel file appears to be invalid or corrupted.');
            }
        }
    }
}
