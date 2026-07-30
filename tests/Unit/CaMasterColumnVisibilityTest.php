<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CaMasterColumnVisibilityTest extends TestCase
{
    private function pagesJs(): string
    {
        return file_get_contents(base_path('public/crm-ui/src/pages/pages.js'));
    }

    private function crmJs(): string
    {
        return file_get_contents(base_path('public/crm-ui/src/api/crm.js'));
    }

    private function expectedKeys(): array
    {
        return [
            'selection',
            'firm_name',
            'email_id',
            'sales_remarks',
            'ca_name',
            'team_size',
            'last_activity',
            'mobile',
            'call_log',
            'alternate_mobile',
            'city',
            'state',
            'source',
            'rating',
            'status',
            'employee',
            'created_by',
            'updated_at',
            'google',
            'actions',
        ];
    }

    #[Test]
    public function ca_master_column_definitions_include_all_real_table_keys(): void
    {
        $js = $this->pagesJs();
        $this->assertStringContainsString('function caMasterColumnDefinitions()', $js);
        foreach ($this->expectedKeys() as $key) {
            $this->assertMatchesRegularExpression('/key:\s*\''.preg_quote($key, '/').'\'/', $js, "Missing column key {$key}");
        }
    }

    #[Test]
    public function required_columns_are_firm_name_actions_and_selection(): void
    {
        $js = $this->pagesJs();
        $this->assertMatchesRegularExpression("/key:\s*'selection'[^}]*required:\s*true/s", $js);
        $this->assertMatchesRegularExpression("/key:\s*'firm_name'[^}]*required:\s*true/s", $js);
        $this->assertMatchesRegularExpression("/key:\s*'actions'[^}]*required:\s*true/s", $js);
        $this->assertMatchesRegularExpression("/key:\s*'employee'[^}]*required:\s*false/s", $js);
    }

    #[Test]
    public function enterprise_table_emits_stable_data_column_attributes(): void
    {
        $js = $this->pagesJs();
        $this->assertStringContainsString('function columnDataAttr(c)', $js);
        $this->assertStringContainsString("data-column=\"", $js);
        $this->assertStringContainsString("key: 'selection'", $js);
    }

    #[Test]
    public function visibility_persists_with_versioned_local_storage_key(): void
    {
        $js = $this->crmJs();
        $this->assertStringContainsString("crm.ca_masters.visible_columns.v2", $js);
        $this->assertStringContainsString("crm.ca_masters.visible_columns.v1", $js);
        $this->assertStringContainsString('CAM_COLUMN_FORCE_SHOW_MIGRATIONS', $js);
        $this->assertStringContainsString('2026_07_email_sales_remarks_v3', $js);
        $this->assertStringContainsString('function applyCaMasterColumnForceShowMigrations', $js);
        $this->assertStringContainsString('function applyCaMasterColumnVisibility', $js);
        $this->assertStringContainsString('function restoreCaMasterDefaultColumns', $js);
        $this->assertStringContainsString('function selectAllCaMasterColumns', $js);
        $this->assertStringContainsString('Manage Columns', $js);
        $this->assertStringContainsString('cam-columns-btn', $js);
    }

    #[Test]
    public function map_lead_record_includes_email_id_and_sales_remarks(): void
    {
        $js = $this->crmJs();
        $this->assertMatchesRegularExpression('/function mapLeadRecord\([\s\S]*?email_id:\s*l\.email_id/', $js);
        $this->assertMatchesRegularExpression('/function mapLeadRecord\([\s\S]*?sales_remarks:\s*l\.sales_remarks/', $js);
    }

    #[Test]
    public function body_and_partner_rows_use_data_column_keys(): void
    {
        $js = $this->crmJs();
        foreach (['firm_name', 'email_id', 'sales_remarks', 'ca_name', 'mobile', 'employee', 'selection'] as $key) {
            $this->assertStringContainsString("camColTd('{$key}'", $js);
        }
        $this->assertStringContainsString("withCamDataColumn('actions'", $js);
        $this->assertStringContainsString("withCamDataColumn('call_log'", $js);
        $this->assertStringContainsString("withCamDataColumn('google'", $js);
        $this->assertStringContainsString('function renderCaMasterPartnerChildRow', $js);
        $this->assertStringContainsString("camColTd('mobile'", $js);
        $this->assertStringContainsString('function salesRemarksCell', $js);
        $this->assertStringContainsString('function truncatedPreviewCell', $js);
        $this->assertStringContainsString('function emailIdCell', $js);
        $this->assertStringContainsString('function latestSalesRemarkPreview', $js);
        $this->assertStringContainsString('function formatSalesRemarksDetailHtml', $js);
        $this->assertStringContainsString("truncatedPreviewCell(latest, 60, 'cam-sales-remarks-cell'", $js);
        $this->assertStringContainsString("truncatedPreviewCell(raw, 60, 'cam-email-cell'", $js);
        $this->assertStringContainsString("label: 'Sales Remarks'", $js);
        $this->assertStringContainsString("formatSalesRemarksDetailHtml(lead.sales_remarks", $js);
    }

    #[Test]
    public function partner_count_uses_same_dataset_as_expanded_partner_rows(): void
    {
        $js = $this->crmJs();
        $this->assertStringContainsString('function normalizeCaPartnerName', $js);
        $this->assertStringContainsString('function resolveCaMasterPartnerGroups', $js);
        $this->assertStringContainsString('var partnerGroups = resolveCaMasterPartnerGroups(l);', $js);
        $this->assertStringContainsString('var partnerCount = partnerGroups.partnerCount;', $js);
        $this->assertStringContainsString('var expandedPartners = partnerGroups.expandedPartners;', $js);
        // Expanded rows must not re-list the main-row CA.
        $this->assertStringContainsString('expandedPartners.map(function (p)', $js);
        $this->assertStringNotContainsString('function resolveCaMasterDisplayPartners', $js);
    }

    #[Test]
    public function unknown_stored_keys_are_normalized_away(): void
    {
        $js = $this->crmJs();
        $this->assertStringContainsString('function normalizeCaMasterVisibleKeys', $js);
        $this->assertStringContainsString('if (!byKey[k] || seen[k]) return;', $js);
    }

    #[Test]
    public function empty_and_loading_colspan_uses_visible_column_count(): void
    {
        $js = $this->crmJs();
        $this->assertStringContainsString('getCaMasterVisibleColumnCount()', $js);
        $this->assertStringNotContainsString('var colCount = 18;', $js);
    }

    #[Test]
    public function column_picker_respects_assignment_permission_gate(): void
    {
        $js = $this->crmJs();
        $this->assertStringContainsString('function caMasterColumnAllowedInPicker', $js);
        $this->assertStringContainsString("permission === 'assignment'", $js);
    }
}
