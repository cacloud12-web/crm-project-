# Master Data Mapping Engine

Single source-of-truth pipeline for OCR, Excel, CSV, and API imports into `ca_masters` (2 lakh+ scale). Manual Approve is only for Conflict / Low Confidence / Missing Required Fields.

## Final workflow

```
Master CA Database (ca_masters)
        ↓
OCR / Excel / CSV / API Import
        ↓
Data Cleaning & Validation
        ↓
Data Normalization (raw preserved)
        ↓
Duplicate Detection (FRN → GST → PAN → Mobile → Alt → Email → Membership → Firm+City → Fuzzy)
        ↓
Intelligent Mapping Engine
        ↓
Existing Firm?  Yes → Update (confidence-aware merge)
                No  → Create
                Multi/Low → Needs Review / Conflict
        ↓
Partner + Mobile mapping (no duplicates)
        ↓
Audit Trail (master_mapping_decisions + master_import_batches)
        ↓
CRM Master → Leads, Follow-ups, Demo, Reports
```

All importers must call:

```php
app(MasterDataMappingService::class)->processBatch($sourceType, $sourceRef, $rows, $actorId, $meta);
```

## Architecture

| Layer | Service |
|-------|---------|
| Normalize | `DataNormalizationService` + `MasterDataMatchingService::normalizePayload()` |
| Index / match | `MasterDataMatchingService::buildIndex()` + `match()` |
| Decide / apply | `MasterDataMappingService` |
| Partners | `PartnerMappingService` |
| Rollback | `MasterImportRollbackService` |
| OCR staging | `OcrStructurePersistService` → `MapOcrParsedFirmsJob` |
| Excel/CSV | `BulkCaMasterImportService` → engine when `CRM_MAPPING_USE_ENGINE_FOR_BULK=true` |
| Manual gate | `OcrFirmApprovalService` |

## Match priority (never invent duplicates)

1. FRN  
2. GST  
3. PAN  
4. Mobile  
5. Alternate mobile  
6. Email  
7. ICAI membership number  
8. Firm name + city  
9. Fuzzy firm name (indexed prefix shortlist only)

## Merge rules

- Never overwrite filled Master fields with lower OCR confidence.
- Empty Master fields may be filled from import.
- New mobile that differs from primary is stored as `alternate_mobile_no` when empty.
- Partners: upsert by membership / name — never duplicate.

## OCR review UI

Auto-created / auto-updated: no Approve required.

Manual only: Conflict, Needs Review, Missing Required Fields.

Actions: Approve All Safe · Reject Selected · Retry Mapping · Rollback import batch.

Duplicate file upload (SHA-256 checksum) warns and blocks unless `force_reimport=1`.

## Progress dashboard

`master_import_batches` tracks: Uploading → Parsing → Mapping → Creating/Updating → Completed, with created / updated / review / conflict / failed counts. Exposed on OCR document detail + `GET /master-import-batches/{id}`.

## Config (`config/crm_mapping.php`)

| Env | Default |
|-----|---------|
| `CRM_MAPPING_AUTO_APPLY_EXACT` | `true` |
| `CRM_MAPPING_AUTO_CREATE` | `true` |
| `CRM_MAPPING_AUTO_UPDATE_MIN` | `0.90` |
| `CRM_MAPPING_REVIEW_MIN` | `0.55` |
| `CRM_MAPPING_FUZZY_AUTO_MIN` | `0.97` |
| `CRM_MAPPING_FIELD_CONFIDENCE_MIN` | `0.55` |
| `CRM_MAPPING_INDEX_CHUNK` | `500` |
| `CRM_MAPPING_MAP_CHUNK` | `200` |
| `CRM_MAPPING_SYNC_MAX_FIRMS` | `50` |
| `CRM_MAPPING_QUEUE_AFTER_OCR` | `true` |
| `CRM_MAPPING_USE_ENGINE_FOR_BULK` | `true` |

## Performance

- Indexed `whereIn` chunks — no full-table scans  
- Fuzzy via `normalized_firm_name LIKE 'prefix%'` + hard limit  
- OCR mapping chunked (`map_chunk_size`) on one rollbackable import batch  
- Queue jobs for large OCR docs  
- Idempotent apply via decisions + staging `match_status`

## Database (additive only)

| Migration | Purpose |
|-----------|---------|
| `2026_07_17_120000_add_master_mapping_engine_columns` | Staging match columns + `master_mapping_decisions` + `normalized_firm_name` |
| `2026_07_17_160000_add_ocr_mapping_scalability_indexes` | Scalability indexes |
| `2026_07_17_180000_add_master_import_batches_and_audit_columns` | `master_import_batches`, audit old/new, `ca_masters.field_confidence` |

Never `migrate:fresh`. Never recreate `ca_reference`.

## Hostinger

```bash
cd ~/path/to/crm-project
php artisan migrate --force
php artisan config:clear
php artisan queue:restart
# Ensure cron runs queue worker / schedule
```

## API

- `GET /master-import-batches/{batch}` — progress counters  
- `POST /master-import-batches/{batch}/rollback` — reverse create/update for that batch  
- OCR upload: `force_reimport=1` to bypass file-hash warning  

## Tests

```bash
php artisan test --filter='MasterDataMapping|MasterImportBatch|OcrDuplicateFile|OcrAutoMapping|DataNormalization|MasterDataMatching'
```
