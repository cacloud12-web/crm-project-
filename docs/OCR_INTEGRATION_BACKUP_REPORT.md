# OCR Integration — Backup & Phase 1 Report

Generated: 2026-07-15 (updated)

## 1. Versions detected
- PHP: **8.3.32**
- Laravel: **13.19.0**
- Package: **google/cloud-document-ai v2.7.0**

## 2. Bulk Import decision — Option B
Keep CSV/Excel **Bulk Import** unchanged. Add **OCR Import** separately (Master Data → OCR Import / `/ocr-import`).

Justification: Bulk Import is a stable CSV/XLSX wizard on `bulk_actions`; OCR uses `ocr_documents` + Document AI queue pipeline. No technical conflict.

## 3. Bulk Assignment
**Not modified for OCR.** (Any unrelated attendance/presence cleanup in Bulk Assignment catalogs is outside this OCR scope.)

## 4–16. Created / extended components
| Area | Paths |
|------|--------|
| Config | `config/document-ai.php`, OCR keys in `.env.example`, `config/rbac.php` (`ocr` module), `config/crm_rate_limits.php` (`ocr_upload`) |
| Contract | `app/Contracts/Ocr/OcrProcessorInterface.php` |
| Services | `app/Services/DocumentAi/GoogleDocumentAiService.php`, `app/Services/Ocr/GoogleDocumentAiService.php`, `app/Services/Ocr/OcrDocumentService.php` |
| Exceptions | `app/Exceptions/Ocr/*`, `app/Exceptions/DocumentAi/*` (extend Ocr) |
| Models | `OcrDocument`, `OcrImportBatch` |
| Migrations | `2026_07_13_120000_create_ocr_documents_table.php`, `2026_07_15_123500_enhance_ocr_documents_for_document_ai.php` |
| Job | `ProcessOcrDocumentJob` |
| HTTP | `OcrDocumentController`, `StoreOcrDocumentRequest`, `UpdateOcrDocumentTextRequest`, `OcrDocumentResource` |
| Policy | `OcrDocumentPolicy` (dedicated `ocr.*` permissions) |
| Routes | `routes/crm/ocr.php` (+ SPA `/ocr-import`) |
| UI | SPA: `ocr-import-page.js`, `ocr-panel.js` (no separate Blade OCR pages — CRM is SPA) |
| Commands | `document-ai:test`, `ocr:verify`, RBAC `crm:rbac-ensure-defaults` |
| Tests | `tests/Feature/OcrDocumentTest.php` |
| Docs | this file, `docs/OCR_HOSTINGER_SETUP.md` |

## Files removed
None.

## Credentials storage path
`storage/app/google/document-ai-service-account.json` (gitignored). **Do not commit.**

## Environment variables
```
GOOGLE_DOCUMENT_AI_PROJECT_ID=
GOOGLE_DOCUMENT_AI_PROCESSOR_ID=
GOOGLE_DOCUMENT_AI_LOCATION=
GOOGLE_APPLICATION_CREDENTIALS=
GOOGLE_DOCUMENT_AI_CREDENTIALS=
GOOGLE_DOCUMENT_AI_TIMEOUT=120
GOOGLE_DOCUMENT_AI_MAX_FILE_MB=20
```

## OCR permissions (RBAC module `ocr`)
`view`, `upload`, `process`, `create`, `edit`, `retry`, `download`, `delete`, `view_all` (+ aliases)

After deploy: `php artisan crm:rbac-ensure-defaults`

## Future mapping preparation
Nullable `ca_id`, `import_batch_id`, `structured_data` JSON, `provider` / `provider_reference`, batches table — ready for CA matching without rebuilding OCR.

## Out of scope (Phase 1)
CA master matching, firm/partner matching, duplicate merge, golden record, auto master-data updates.
