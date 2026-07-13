<?php

namespace App\Http\Requests;

use App\Models\CaMaster;
use App\Services\Rbac\EmployeeDataScopeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreOcrDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('create', \App\Models\OcrDocument::class);
    }

    public function rules(): array
    {
        $maxKb = max(1, (int) config('document-ai.max_file_mb', 10)) * 1024;

        return [
            'ca_id' => ['required', 'integer', 'exists:ca_masters,ca_id'],
            'document' => [
                'required',
                'file',
                'max:'.$maxKb,
                'mimetypes:'.implode(',', config('document-ai.supported_mime_types', [])),
                'mimes:'.implode(',', config('document-ai.supported_extensions', [])),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $caId = (int) $this->input('ca_id');
            if ($caId <= 0) {
                return;
            }

            try {
                app(EmployeeDataScopeService::class)->ensureCanAccessCaMaster($caId);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                $validator->errors()->add('ca_id', 'You do not have access to this lead.');
            }

            if (! CaMaster::query()->whereKey($caId)->exists()) {
                $validator->errors()->add('ca_id', 'The selected lead could not be found.');
            }

            $file = $this->file('document');
            if ($file && $file->getSize() === 0) {
                $validator->errors()->add('document', 'The uploaded document is empty.');
            }
        });
    }
}
