<?php

namespace App\Services\Mapping;

use App\Models\MasterMappingDecision;
use App\Models\SalesImportRow;
use Illuminate\Support\Facades\Schema;

/**
 * Centralized remap protection for employee sales-import rows.
 * Never remaps manual Confirm / Accept / Ignore / Reject decisions.
 */
class SalesImportRemapProtection
{
    public const SOURCE_TYPE = 'sales_import_row';

    /** @var list<string> */
    public const PROTECTED_MATCHED_ON = [
        SalesImportReviewService::ACTION_CONFIRM, // manual_confirmed
        'manual_confirm',
        SalesImportReviewService::ACTION_ACCEPT_TOP,
        SalesImportReviewService::ACTION_ACCEPT_MATCHED,
        SalesImportReviewService::ACTION_UNMATCHED, // mark_unmatched
        SalesImportReviewService::ACTION_IGNORE,
        'rejected',
    ];

    /** @var list<string> */
    public const PROTECTED_AUDIT_DECISIONS = [
        'manual_confirm',
        'manual_confirmed',
        MasterMappingDecision::DECISION_REJECTED,
        MasterMappingDecision::DECISION_SKIPPED,
        'ignored',
        'mark_unmatched',
        'accepted_top_candidate',
        'accepted_matched',
    ];

    /**
     * @return array{protected: bool, reason: string|null}
     */
    public function inspect(
        SalesImportRow $row,
        bool $includeAutoMatched = false,
        bool $includeManualUnmatched = false,
    ): array {
        $status = strtolower(trim((string) ($row->mapping_status ?? '')));
        $matchedOn = strtolower(trim((string) ($row->matched_on ?? '')));

        if ($status === 'ignored') {
            return ['protected' => true, 'reason' => 'mapping_status=ignored'];
        }

        if (in_array($matchedOn, ['manual_confirmed', 'manual_confirm'], true)) {
            return ['protected' => true, 'reason' => 'matched_on=manual_confirmed'];
        }

        if ($matchedOn === SalesImportReviewService::ACTION_ACCEPT_TOP) {
            return ['protected' => true, 'reason' => 'matched_on=accepted_top_candidate'];
        }

        if ($matchedOn === SalesImportReviewService::ACTION_ACCEPT_MATCHED) {
            return ['protected' => true, 'reason' => 'matched_on=accepted_matched'];
        }

        if ($matchedOn === SalesImportReviewService::ACTION_IGNORE) {
            return ['protected' => true, 'reason' => 'matched_on=ignore'];
        }

        if ($matchedOn === 'rejected') {
            return ['protected' => true, 'reason' => 'matched_on=rejected'];
        }

        if ($matchedOn === SalesImportReviewService::ACTION_UNMATCHED && ! $includeManualUnmatched) {
            return ['protected' => true, 'reason' => 'matched_on=mark_unmatched (use --include-manual-unmatched)'];
        }

        if ($status === 'matched' && ! $includeAutoMatched) {
            return ['protected' => true, 'reason' => 'mapping_status=matched (use --include-auto-matched)'];
        }

        if ($status === 'matched' && $includeAutoMatched && $row->matched_ca_id && in_array($matchedOn, [
            'manual_confirmed', 'manual_confirm',
            SalesImportReviewService::ACTION_ACCEPT_TOP,
            SalesImportReviewService::ACTION_ACCEPT_MATCHED,
        ], true)) {
            return ['protected' => true, 'reason' => 'administrator-selected matched row'];
        }

        $auditReason = $this->auditProtectionReason($row, $includeManualUnmatched);
        if ($auditReason !== null) {
            return ['protected' => true, 'reason' => $auditReason];
        }

        $eligibleStatuses = ['unmatched', 'needs_review', 'pending', ''];
        if ($includeAutoMatched) {
            $eligibleStatuses[] = 'matched';
        }

        if (! in_array($status, $eligibleStatuses, true)) {
            return ['protected' => true, 'reason' => 'mapping_status='.($status !== '' ? $status : '(empty)').' not eligible'];
        }

        return ['protected' => false, 'reason' => null];
    }

    public function isProtected(
        SalesImportRow $row,
        bool $includeAutoMatched = false,
        bool $includeManualUnmatched = false,
    ): bool {
        return $this->inspect($row, $includeAutoMatched, $includeManualUnmatched)['protected'];
    }

    private function auditProtectionReason(SalesImportRow $row, bool $includeManualUnmatched): ?string
    {
        if (! Schema::hasTable('master_mapping_decisions')) {
            return null;
        }

        $query = MasterMappingDecision::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_ref', (string) $row->id)
            ->whereIn('decision', self::PROTECTED_AUDIT_DECISIONS)
            ->orderByDesc('id');

        $decision = $query->value('decision');
        if ($decision === null) {
            return null;
        }

        $decision = (string) $decision;
        if (in_array($decision, [MasterMappingDecision::DECISION_REJECTED, 'mark_unmatched'], true) && $includeManualUnmatched) {
            return null;
        }

        if (in_array($decision, ['manual_confirm', 'manual_confirmed', MasterMappingDecision::DECISION_SKIPPED, 'ignored', 'accepted_top_candidate', 'accepted_matched'], true)) {
            return 'master_mapping_decisions.decision='.$decision;
        }

        if (in_array($decision, [MasterMappingDecision::DECISION_REJECTED, 'mark_unmatched'], true)) {
            return 'master_mapping_decisions.decision='.$decision.' (use --include-manual-unmatched)';
        }

        return 'master_mapping_decisions.decision='.$decision;
    }
}
