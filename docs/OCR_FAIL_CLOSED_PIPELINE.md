# Fail-Closed OCR Validation Pipeline

## Guarantee

This pipeline does **not** claim 100% OCR accuracy.

It guarantees: **100% verified correctness of data that reaches final CRM Master Data.**

Unverified, colliding, low-confidence, or ambiguous OCR rows stay in staging (`needs_review` / rejected) and never auto-create or auto-update `ca_masters`.

## Root cause (production integrity)

OCR table/line parsers mixed visual columns:

- address text landed in partner / CA name
- PIN codes landed in membership number
- firm / CA names swapped or merged across rows
- low-confidence guesses were still eligible for auto Master writes

Unsafe paths that are now blocked:

- Fuzzy OCR auto-create / auto-update while `OCR_REQUIRE_VERIFICATION=true`
- Bulk “Approve All Safe” while verification is required (`OCR_ALLOW_BULK_APPROVE_SAFE=false`)
- Silent spelling / field remapping (raw OCR preserved; normalized used only for matching)
- Excel/CSV uploaded through OCR (forced to Bulk Import)
- Master write when cross-field collision codes remain (unless human-corrected)

## Pipeline

```
Source file
→ raw OCR extraction (Document AI tables/cells/bboxes)
→ structured staging (ocr_parsed_firms / members)
→ field validation + collision detection
→ source-versus-output verification
→ mapping / Master CA import (auto-write disabled by default)
→ final Master only after human Approve (or explicit safe auto policy)
```

## Production defaults

```
OCR_AUTO_CREATE=false
OCR_AUTO_UPDATE=false
OCR_REQUIRE_VERIFICATION=true
OCR_REJECT_ON_FIELD_COLLISION=true
OCR_REJECT_ON_ROW_AMBIGUITY=true
OCR_MIN_REQUIRED_FIELD_CONFIDENCE=0.99
OCR_ALLOW_FUZZY_AUTO_APPLY=false
OCR_ALLOW_BULK_APPROVE_SAFE=false
```

## Files changed (key)

| Area | Files |
|------|--------|
| Safety config | `config/ocr_safety.php`, `config/crm_mapping.php`, `.env.example` |
| Collision / verify | `OcrFieldCollisionService`, `OcrSourceVerificationService`, `OcrFieldValidationService` |
| Routing | `OcrImportRouterService`, `OcrDocumentService` |
| Staging | `OcrStructurePersistService`, `OcrParsedFirm` |
| Master gates | `MasterCaDirectImportService`, `MasterDataMappingService`, `OcrFirmApprovalService` |
| API / UI | `OcrDocumentController`, `routes/crm/ocr.php`, `OcrParsedFirmResource`, `ocr-import-page.js`, `styles.css` |
| Tests | `OcrFailClosedSafetyTest`, `OcrFailClosedMasterWriteTest`, golden fixture |

## Migrations

No new migration required for this fail-closed pass (uses existing staging columns from `2026_07_17_220000_add_ocr_accuracy_staging_columns`).

**Do not run `migrate:fresh`.** On Hostinger run only pending migrations if any were not applied yet:

```bash
php artisan migrate --force
```

## Validation rules / collision codes

- `ADDRESS_IN_PARTNER_FIELD`, `ADDRESS_IN_CA_NAME`, `ADDRESS_IN_FIRM_NAME`
- `PIN_IN_MEMBERSHIP_FIELD`, `PIN_IN_FIRM_NAME`
- `MOBILE_IN_FIRM_NAME`, `MOBILE_IN_CA_NAME`, `MOBILE_IN_MEMBERSHIP_FIELD`
- `ROW_MERGE_SUSPECTED`, `ROW_SPLIT_SUSPECTED`, `AMBIGUOUS_TABLE_STRUCTURE`
- `SOURCE_COLUMN_MISMATCH`, `LOW_FIELD_CONFIDENCE`, `MISSING_REQUIRED_FIELD`
- `INCOMPATIBLE_FIELD_OVERLAP`, `FRN_IN_WRONG_FIELD`

## Review workflow

1. Open OCR document → firm cards show **Source | Raw OCR | Parsed** triptych.
2. Collision / validation codes are highlighted.
3. **Correct field** → PATCH fields (preserves raw; marks `human_corrected`).
4. **Approve verified** → single-row Master write (still blocked if collisions remain and not corrected).
5. **Reject** / **Reject selected** → never reaches Master.
6. **Re-run extraction** → reparse / retry mapping.
7. Bulk Approve All Safe is off by default.

## Hostinger deployment

```bash
cd /path/to/crm-project
git pull
composer install --no-dev --optimize-autoloader
# Copy new OCR_* keys from .env.example into .env (keep fail-closed defaults)
php artisan config:clear
php artisan cache:clear
php artisan migrate --force   # never migrate:fresh
php artisan queue:restart     # if using workers
# Hard-refresh browser for crm-ui assets
```

## Manual verification checklist

- [ ] Upload Excel/CSV via OCR → rejected with message to use Bulk Import
- [ ] Upload sample PDF → rows land in Needs Review (0 silent Master inserts)
- [ ] Firm card shows Source / Raw / Parsed side-by-side
- [ ] Address-in-partner sample shows `ADDRESS_IN_PARTNER_FIELD`
- [ ] PIN-as-membership shows `PIN_IN_MEMBERSHIP_FIELD`
- [ ] Approve All Safe returns 422 / policy message
- [ ] Correct fields → Approve → Master row created inside a transaction
- [ ] Rejected row has no `crm_ca_id` and no new Master row
- [ ] Reconciliation / quality report counts still sum (no silent disappear)

## Confirmation

With production defaults, **no unverified OCR row can modify Master Data**.
Only human-approved (or explicitly enabled auto policy) verified staging rows may write Master.
