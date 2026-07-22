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
        $this->assertStringContainsString("crm.ca_masters.visible_columns.v1", $js);
        $this->assertStringContainsString('function applyCaMasterColumnVisibility', $js);
        $this->assertStringContainsString('function restoreCaMasterDefaultColumns', $js);
        $this->assertStringContainsString('function selectAllCaMasterColumns', $js);
        $this->assertStringContainsString('Manage Columns', $js);
        $this->assertStringContainsString('cam-columns-btn', $js);
    }

    #[Test]
    public function body_and_partner_rows_use_data_column_keys(): void
    {
        $js = $this->crmJs();
        foreach (['firm_name', 'ca_name', 'mobile', 'employee', 'actions', 'selection'] as $key) {
            $this->assertStringContainsString("camColTd('{$key}'", $js);
        }
        $this->assertStringContainsString("withCamDataColumn('call_log'", $js);
        $this->assertStringContainsString("withCamDataColumn('google'", $js);
        $this->assertStringContainsString('function renderCaMasterPartnerChildRow', $js);
        $this->assertStringContainsString("camColTd('mobile'", $js);
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
