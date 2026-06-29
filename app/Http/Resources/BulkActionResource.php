<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BulkActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'bulk_action_id' => $this->bulk_action_id,
            'action_type' => $this->action_type,
            'file_name' => $this->file_name,
            'format' => $this->export_format,
            'total_rows' => $this->total_records,
            'processed_rows' => $this->processed_records,
            'inserted_rows' => $this->success_records,
            'exported_rows' => $this->action_type === 'ca_master_export' ? $this->success_records : null,
            'duplicate_rows' => $this->duplicate_records,
            'failed_rows' => $this->failed_records,
            'skipped_rows' => $this->skipped_records,
            'imported_by' => $this->imported_by ?? 'System',
            'exported_by' => $this->action_type === 'ca_master_export' ? ($this->imported_by ?? 'System') : null,
            'status' => $this->status,
            'progress_percent' => $this->total_records > 0
                ? min(100, (int) round(($this->processed_records / max($this->total_records, 1)) * 100))
                : 0,
            'download_ready' => $this->action_type === 'ca_master_export'
                && $this->status === 'Completed'
                && (bool) $this->output_path,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
        ];
    }
}
