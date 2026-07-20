<?php

namespace App\Console\Commands;

use App\Models\CaMaster;
use App\Services\Leads\CaMasterPartnerService;
use Illuminate\Console\Command;

/**
 * Backfill Merchant Centre partners from OCR staging (and primary ca_name fallback).
 */
class CaMasterBackfillPartnersCommand extends Command
{
    protected $signature = 'ca-masters:backfill-partners
        {--limit=0 : Max firms to process (0 = all)}
        {--ocr-only : Only sync firms that have linked OCR staging rows}
        {--force : Re-sync OCR partners even when CRM partners already exist}';

    protected $description = 'Sync ca_master_partners from OCR members/partners (fallback: primary ca_name)';

    public function handle(CaMasterPartnerService $partners): int
    {
        if (! $partners->tableReady()) {
            $this->error('ca_master_partners table is missing. Run migrations first.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $ocrOnly = (bool) $this->option('ocr-only');
        $force = (bool) $this->option('force');

        $query = CaMaster::withTrashed()->orderBy('ca_id');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $scanned = 0;
        $fromOcr = 0;
        $primaryOnly = 0;
        $restored = 0;

        $query->chunkById(200, function ($firms) use ($partners, $ocrOnly, $force, &$scanned, &$fromOcr, &$primaryOnly, &$restored) {
            foreach ($firms as $firm) {
                $scanned++;
                if ($firm->trashed()) {
                    // OCR-linked masters that were rolled back still need partners + visibility.
                    $hasOcr = \Illuminate\Support\Facades\Schema::hasTable('ocr_parsed_firms')
                        && \App\Models\OcrParsedFirm::query()
                            ->where(function ($q) use ($firm) {
                                $q->where('crm_ca_id', $firm->ca_id)->orWhere('matched_ca_id', $firm->ca_id);
                            })->exists();
                    if ($hasOcr) {
                        $firm->restore();
                        $restored++;
                    } elseif ($ocrOnly) {
                        continue;
                    }
                }

                $existing = $firm->partners()->count();
                if ($existing > 0 && ! $force) {
                    if ($existing === 1) {
                        $synced = $partners->syncFromLinkedOcr($firm);
                        if ($synced > 1) {
                            $fromOcr++;
                        }
                    }

                    continue;
                }

                $synced = $partners->syncFromLinkedOcr($firm);
                if ($synced > 0) {
                    $fromOcr++;

                    continue;
                }

                if ($ocrOnly) {
                    continue;
                }

                $before = $firm->partners()->count();
                $partners->ensurePrimaryFromMaster($firm);
                if ($before === 0 && $firm->partners()->count() > 0) {
                    $primaryOnly++;
                }
            }
        }, 'ca_id');

        $this->info("scanned={$scanned} restored={$restored} synced_from_ocr={$fromOcr} primary_fallback={$primaryOnly}");

        return self::SUCCESS;
    }
}
