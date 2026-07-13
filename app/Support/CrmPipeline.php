<?php

namespace App\Support;

class CrmPipeline
{
    /**
     * @return list<string>
     */
    public static function pipelineSegmentStatuses(): array
    {
        return config('crm_statuses.pipeline_segment', []);
    }

    public static function masterStageForStatus(?string $status): string
    {
        $status = trim((string) $status);
        $stages = config('crm_master_pipeline.stage_statuses', []);

        foreach ($stages as $stage => $statuses) {
            if (in_array($status, $statuses, true)) {
                return (string) $stage;
            }
        }

        return 'New Lead';
    }

    public static function masterStatusForStage(?string $stage): string
    {
        $stage = trim((string) $stage);
        $map = config('crm_master_pipeline.stage_to_status', []);

        return (string) ($map[$stage] ?? 'New');
    }

    public static function salesStageForStatus(?string $status): string
    {
        $status = trim((string) $status);
        $stages = config('crm_sales_pipeline.stage_statuses', []);

        foreach ($stages as $stage => $statuses) {
            if (in_array($status, $statuses, true)) {
                return (string) $stage;
            }
        }

        return 'New Lead';
    }

    public static function salesStatusForStage(?string $stage): string
    {
        $stage = trim((string) $stage);
        $map = config('crm_sales_pipeline.stage_to_status', []);

        return (string) ($map[$stage] ?? 'New');
    }

    /**
     * @return array<string, list<string>>
     */
    public static function salesStageStatuses(): array
    {
        return config('crm_sales_pipeline.stage_statuses', []);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function masterStageStatuses(): array
    {
        return config('crm_master_pipeline.stage_statuses', []);
    }
}
