<?php

namespace Tests\Unit;

use Tests\Support\CrmTestAccounts;

use App\Models\CaMaster;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Models\User;
use App\Services\Leads\CaMasterService;
use App\Services\Leads\PhoneNormalizationService;
use App\Support\Listing\ListingQueryApplier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class CaMasterListingFiltersTest extends TestCase
{
    use DatabaseTransactions;

    private array $seededIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $admin = CrmTestAccounts::admin();
        Auth::login($admin);

        $ts = (string) microtime(true);
        foreach ([
            ['firm_name' => 'FilterTest Low '.$ts, 'rating' => 2, 'team_size' => 3],
            ['firm_name' => 'FilterTest Mid '.$ts, 'rating' => 4, 'team_size' => 8],
            ['firm_name' => 'FilterTest High '.$ts, 'rating' => 5, 'team_size' => 15],
        ] as $i => $row) {
            $lead = CaMaster::query()->create([
                'firm_name' => $row['firm_name'],
                'ca_name' => 'Filter CA '.$i,
                'mobile_no' => '5'.substr(str_replace('.', '', $ts), -9).$i,
                'email_id' => "filter{$i}_{$ts}@test.local",
                'rating' => $row['rating'],
                'team_size' => $row['team_size'],
                'status' => $i === 2 ? 'Hot' : 'New',
            ]);
            $this->seededIds[] = $lead->ca_id;
        }
    }

    public function test_rating_min_filter_maps_to_rating_column(): void
    {
        $result = $this->search(['rating_min' => 4, 'search' => 'FilterTest']);

        $this->assertGreaterThanOrEqual(2, $result['pagination']['total']);
        foreach ($result['items'] as $item) {
            $this->assertGreaterThanOrEqual(4, (int) $item->rating);
        }
    }

    public function test_rating_max_filter_maps_to_rating_column(): void
    {
        $result = $this->search(['rating_max' => 4, 'search' => 'FilterTest']);

        $this->assertGreaterThanOrEqual(2, $result['pagination']['total']);
        foreach ($result['items'] as $item) {
            $this->assertLessThanOrEqual(4, (int) $item->rating);
        }
    }

    public function test_rating_min_and_max_combined(): void
    {
        $result = $this->search([
            'rating_min' => 4,
            'rating_max' => 4,
            'search' => 'FilterTest Mid',
        ]);

        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame(4, (int) $result['items'][0]->rating);
    }

    public function test_team_size_filters_still_work(): void
    {
        $result = $this->search([
            'team_size_min' => 5,
            'team_size_max' => 10,
            'search' => 'FilterTest Mid',
        ]);

        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame(8, (int) $result['items'][0]->team_size);
    }

    public function test_rating_and_team_size_filters_together(): void
    {
        $result = $this->search([
            'rating_min' => 4,
            'team_size_min' => 10,
            'search' => 'FilterTest High',
        ]);

        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame(5, (int) $result['items'][0]->rating);
        $this->assertSame(15, (int) $result['items'][0]->team_size);
    }

    public function test_status_exact_filter_still_works(): void
    {
        $result = $this->search(['status' => 'Hot', 'search' => 'FilterTest High']);

        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame('Hot', $result['items'][0]->status);
    }

    public function test_email_id_column_filter_works(): void
    {
        $email = 'emailfilter'.str_replace('.', '', (string) microtime(true)).'@test.local';
        CaMaster::query()->where('ca_id', $this->seededIds[1])->update(['email_id' => $email]);

        $result = $this->search([
            'email_id' => $email,
            'search' => 'FilterTest',
        ]);

        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame($email, $result['items'][0]->email_id);
    }

    public function test_sales_remarks_column_filter_and_global_search(): void
    {
        $marker = 'RemarksMarker'.str_replace('.', '', (string) microtime(true));
        CaMaster::query()->where('ca_id', $this->seededIds[0])->update([
            'sales_remarks' => 'Alpha '.$marker.' omega notes for filter',
        ]);

        $byFilter = $this->search(['sales_remarks' => $marker]);
        $this->assertSame(1, $byFilter['pagination']['total']);
        $this->assertStringContainsString($marker, (string) $byFilter['items'][0]->sales_remarks);

        $bySearch = $this->search(['search' => $marker]);
        $this->assertGreaterThanOrEqual(1, $bySearch['pagination']['total']);
        $ids = collect($bySearch['items'])->pluck('ca_id')->all();
        $this->assertContains($this->seededIds[0], $ids);
    }

    public function test_listing_applier_does_not_throw_for_rating_filters(): void
    {
        $config = ListingQueryApplier::config('ca_masters');
        $query = CaMaster::query()->where('firm_name', 'like', 'FilterTest%');

        $result = ListingQueryApplier::apply($query, [
            'rating_min' => 1,
            'rating_max' => 5,
            'team_size_min' => 1,
            'team_size_max' => 50,
            'per_page' => 25,
        ], $config);

        $this->assertGreaterThanOrEqual(3, $result['pagination']['total']);
    }

    public function test_mobile_no_column_filter_matches_formatted_indian_mobile_using_displayed_digits(): void
    {
        $lead = $this->seedFormattedMobileLead();

        foreach (['919819779602', '9819779602', '+91 98197 79602'] as $mobileFilter) {
            $result = $this->search(['mobile_no' => $mobileFilter, 'firm_name' => 'MobileFilterFirm']);
            $this->assertSame(1, $result['pagination']['total'], "Failed for mobile_no={$mobileFilter}");
            $this->assertSame($lead->ca_id, $result['items'][0]->ca_id);
        }
    }

    public function test_mobile_no_column_filter_still_supports_partial_and_plain_mobile_values(): void
    {
        $formattedLead = $this->seedFormattedMobileLead();
        $plainMobile = '9'.substr(str_replace('.', '', (string) microtime(true)), -9);
        $plainLead = CaMaster::query()->create([
            'firm_name' => 'MobileFilterPlain '.microtime(true),
            'ca_name' => 'Plain Mobile CA',
            'mobile_no' => $plainMobile,
            'normalized_mobile' => app(PhoneNormalizationService::class)->normalize($plainMobile),
            'status' => 'New',
        ]);

        $partialFormatted = $this->search(['mobile_no' => '98197', 'firm_name' => 'MobileFilterFirm']);
        $this->assertSame(1, $partialFormatted['pagination']['total']);
        $this->assertSame($formattedLead->ca_id, $partialFormatted['items'][0]->ca_id);

        $lastDigitsFormatted = $this->search(['mobile_no' => '79602', 'firm_name' => 'MobileFilterFirm']);
        $this->assertSame(1, $lastDigitsFormatted['pagination']['total']);
        $this->assertSame($formattedLead->ca_id, $lastDigitsFormatted['items'][0]->ca_id);

        $plainResult = $this->search(['mobile_no' => $plainMobile]);
        $this->assertSame(1, $plainResult['pagination']['total']);
        $this->assertSame($plainLead->ca_id, $plainResult['items'][0]->ca_id);
    }

    public function test_global_search_matches_formatted_mobile_using_displayed_digits(): void
    {
        $lead = $this->seedFormattedMobileLead();

        $result = $this->search(['search' => '919819779602']);
        $ids = collect($result['items'])->pluck('ca_id')->all();

        $this->assertContains($lead->ca_id, $ids);
    }

    public function test_firm_name_filter_still_works_after_mobile_search_changes(): void
    {
        $lead = $this->seedFormattedMobileLead();

        $result = $this->search(['firm_name' => $lead->firm_name]);

        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame($lead->ca_id, $result['items'][0]->ca_id);
    }

    public function test_mobile_search_does_not_break_employee_scope(): void
    {
        $employee = CrmTestAccounts::employee();
        $employeeUser = CrmTestAccounts::employeeUser();
        $otherEmployee = Employee::factory()->create([
            'name' => 'Other Scope Employee',
            'email_id' => 'other.scope.'.microtime(true).'@example.test',
            'status' => 'Active',
        ]);

        $assignedLead = CaMaster::query()->create([
            'firm_name' => 'Scoped Mobile Firm '.microtime(true),
            'ca_name' => 'Scoped Mobile CA',
            'mobile_no' => '+91 98197 79602',
            'normalized_mobile' => '9819779602',
            'status' => 'New',
        ]);
        LeadAssignmentEngine::query()->create([
            'ca_id' => $assignedLead->ca_id,
            'employee_id' => $employee->employee_id,
            'status' => 'Active',
            'assigned_date' => now()->toDateString(),
        ]);

        $otherLead = CaMaster::query()->create([
            'firm_name' => 'Other Scoped Mobile Firm '.microtime(true),
            'ca_name' => 'Other Scoped Mobile CA',
            'mobile_no' => '+91 98197 79602',
            'normalized_mobile' => '9819779602',
            'status' => 'New',
        ]);
        LeadAssignmentEngine::query()->create([
            'ca_id' => $otherLead->ca_id,
            'employee_id' => $otherEmployee->employee_id,
            'status' => 'Active',
            'assigned_date' => now()->toDateString(),
        ]);

        Auth::login($employeeUser);

        $result = $this->search(['mobile_no' => '919819779602']);
        $ids = collect($result['items'])->pluck('ca_id')->all();

        $this->assertContains($assignedLead->ca_id, $ids);
        $this->assertNotContains($otherLead->ca_id, $ids);
    }

    private function seedFormattedMobileLead(): CaMaster
    {
        return CaMaster::query()->create([
            'firm_name' => 'MobileFilterFirm '.microtime(true),
            'ca_name' => 'Formatted Mobile CA',
            'mobile_no' => '+91 98197 79602',
            'normalized_mobile' => '9819779602',
            'status' => 'New',
        ]);
    }

    private function search(array $params): array
    {
        return app(CaMasterService::class)->search(array_merge(['per_page' => 25], $params));
    }
}
