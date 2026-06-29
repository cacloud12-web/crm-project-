<?php

namespace App\Http\Requests\Bulk;

class BulkExportStoreRequest extends BulkExportPreviewRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'format' => ['required', 'in:csv,xlsx'],
            'performed_by' => ['nullable', 'string', 'max:120'],
        ]);
    }
}
