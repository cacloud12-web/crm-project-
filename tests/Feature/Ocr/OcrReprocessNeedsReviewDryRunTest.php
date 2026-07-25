<?php

namespace Tests\Feature\Ocr;

use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Services\Ocr\OcrNeedsReviewProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OcrReprocessNeedsReviewDryRunTest extends TestCase
{
    public function test_command_defaults_to_dry_run_and_refuses_apply(): void
    {
        $this->artisan('ocr:reprocess-needs-review', ['--apply' => true])
            ->assertFailed();
    }

    public function test_dry_run_performs_zero_db_writes(): void
    {
        if (! Schema::hasTable('ocr_parsed_firms')) {
            $this->markTestSkipped('ocr_parsed_firms missing');
        }

        $before = DB::table('ocr_parsed_firms')->where('match_status', 'needs_review')
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(id),0) as s')->first();

        $this->artisan('ocr:reprocess-needs-review', [
            '--limit' => 5,
            '--export' => storage_path('app/ocr-audits/test-nr-dryrun.csv'),
        ])->assertSuccessful();

        $after = DB::table('ocr_parsed_firms')->where('match_status', 'needs_review')
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(id),0) as s')->first();

        $this->assertSame((int) $before->c, (int) $after->c);
        $this->assertSame((string) $before->s, (string) $after->s);
    }

    public function test_proposal_does_not_fabricate_ca_when_ocr_lacks_person(): void
    {
        $svc = new OcrNeedsReviewProposalService;
        $firm = new OcrParsedFirm([
            'id' => 999999001,
            'ocr_document_id' => 0,
            'firm_name' => 'PBSD & ASSOCIATES',
            'city' => 'DELHI',
            'page_number' => 1,
            'match_status' => 'needs_review',
            'match_reason' => 'ca_name: CA name is required.',
            'source_data' => [
                'raw' => ['firm_name' => 'PBSD & ASSOCIATES', 'ca_name' => '', 'city' => 'DELHI'],
                'parsed' => ['firm_name' => 'PBSD & ASSOCIATES', 'ca_name' => '', 'city' => 'DELHI'],
            ],
        ]);
        $firm->id = 999999001;

        $proposal = $svc->proposeOne($firm, null, true);
        $this->assertSame('', $proposal['proposed_ca_name']);
        $this->assertFalse($proposal['complete_after']);
        $this->assertContains($proposal['derived_category'], ['C', 'D']);
    }

    public function test_chunk_and_resume_options_accepted(): void
    {
        $this->artisan('ocr:reprocess-needs-review', [
            '--limit' => 2,
            '--resume-from' => 1,
            '--chunk' => 50,
            '--export' => storage_path('app/ocr-audits/test-nr-resume.csv'),
        ])->assertSuccessful();
    }
}
