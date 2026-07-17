# OCR Document AI — Hostinger Deployment

## Local setup
1. Place the service-account JSON at:
   `storage/app/google/document-ai-service-account.json`
2. Fill private `.env` (never commit secrets):
   ```
   GOOGLE_DOCUMENT_AI_PROJECT_ID=...
   GOOGLE_DOCUMENT_AI_PROCESSOR_ID=...
   GOOGLE_DOCUMENT_AI_LOCATION=us
   GOOGLE_DOCUMENT_AI_CREDENTIALS=storage/app/google/document-ai-service-account.json
   # Optional absolute path — quote if the path contains spaces:
   # GOOGLE_APPLICATION_CREDENTIALS="/absolute/path/to/document-ai-service-account.json"
   GOOGLE_APPLICATION_CREDENTIALS=
   GOOGLE_DOCUMENT_AI_TIMEOUT=120
   GOOGLE_DOCUMENT_AI_MAX_FILE_MB=100
   GOOGLE_DOCUMENT_AI_ONLINE_MAX_PAGES=30
   GOOGLE_DOCUMENT_AI_BATCH_MAX_PAGES=500
   GOOGLE_DOCUMENT_AI_ONLINE_MAX_FILE_MB=40
   GOOGLE_DOCUMENT_AI_BATCH_MAX_FILE_MB=1024
   GOOGLE_DOCUMENT_AI_BATCH_POLL_SECONDS=10
   GOOGLE_DOCUMENT_AI_BATCH_TIMEOUT_MINUTES=60
   GOOGLE_CLOUD_STORAGE_INPUT_BUCKET=your-private-ocr-input-bucket
   GOOGLE_CLOUD_STORAGE_OUTPUT_BUCKET=your-private-ocr-output-bucket
   GOOGLE_OCR_DELETE_GCS_INPUT_AFTER_SUCCESS=true
   GOOGLE_OCR_DELETE_GCS_OUTPUT_AFTER_SUCCESS=false
   QUEUE_CONNECTION=database
   ```
3. **Required:** copy your Google Cloud service-account JSON key to:
   `storage/app/google/document-ai-service-account.json`
   (`.env` already points there. Without this file, OCR cannot call Google.)
4. Run:
   ```
   composer install
   composer dump-autoload
   php artisan optimize:clear
   php artisan migrate
   php artisan crm:rbac-ensure-defaults
   php artisan ocr:verify
   php artisan document-ai:test /absolute/path/to/sample.pdf
   php artisan test --filter=OcrDocumentTest
   php artisan test --filter=OcrHybridProcessingTest
   ```

## Hybrid OCR (online + batch)
- **Online:** images and PDFs ≤ `GOOGLE_DOCUMENT_AI_ONLINE_MAX_PAGES` (default 30) and within online size limits.
- **Batch:** larger PDFs (e.g. 262 pages) upload to private GCS → Document AI `batchProcessDocuments` → delayed status polls → JSON finalization.
- Page counts use `smalot/pdfparser` (no Hostinger shell dependency).
- Create **two private** GCS buckets, then grant the Document AI service account:
  - Input bucket: `storage.objects.create`, `storage.objects.get`, `storage.objects.delete`
  - Output bucket: `storage.objects.get`, `storage.objects.list`, `storage.objects.delete` (delete only if cleanup is enabled)
  - Document AI also needs permission to read the input objects and write output objects (Document AI Service Agent + your SA roles as required by Google).

Without GCS buckets configured, small-file online OCR still works; large uploads return a clear configuration error instead of failing after a long online attempt.

## Hostinger setup (shared hosting)
1. Upload code (exclude `.env`, vendor rebuild on server or upload vendor carefully).
2. Place JSON **outside** `public_html` ideally, or under `storage/app/google/` (never under `public/`).
3. Set env as above; `GOOGLE_DOCUMENT_AI_LOCATION` is region `us` / `eu`, not language.
4. PHP **8.3+** with extensions: openssl, curl, json, mbstring, fileinfo, pdo, pdo_mysql.
5. Raise PHP limits for large uploads (`upload_max_filesize` / `post_max_size` above app max).
6. Permissions:
   ```
   chmod -R ug+rwx storage bootstrap/cache
   ```
7. Prefer `QUEUE_CONNECTION=database` (jobs table already exists).
8. Small online files (≤5 pages / ≤5 MB by default) use a **sync fast path** and complete in the upload request — no worker wait.
9. Large / batch OCR still uses the database queue. On Hostinger shared hosting use PHP 8.3 and cron-driven workers (Supervisor may be unavailable):
```
* * * * * cd /home/USER/domains/YOURDOMAIN/public_html && /opt/alt/php83/usr/bin/php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/USER/domains/YOURDOMAIN/public_html && /opt/alt/php83/usr/bin/php artisan queue:work --stop-when-empty --tries=3 --timeout=300 >> /home/USER/queue-worker.log 2>&1
```
10. Optional recovery: `/opt/alt/php83/usr/bin/php artisan ocr:recover-stuck`
11. CA Reference (separate DB): `/opt/alt/php83/usr/bin/php artisan ca-reference:verify`
12. OCR readiness: `/opt/alt/php83/usr/bin/php artisan ocr:verify`

### Local development
With `QUEUE_CONNECTION=database`, either:
- rely on small-file sync fast path + after-response queue drain, or
- run `php artisan queue:listen --tries=3` / `php artisan schedule:work` in a second terminal.

## Proof-of-concept
```
php artisan document-ai:test /path/to/sample.pdf
```
Prints a short text preview only. Never prints credentials. (Online ProcessDocument — use a small PDF.)

## UI
OCR Import: Master Data → Bulk Tools → OCR Import (`/ocr-import`).

- Upload spinner covers **browser→Laravel only** (with %).
- History shows Queued / Uploading to cloud / Processing / Finalizing / Completed.
- Large documents show a batch notice; CRM stays usable during background OCR.

CSV Bulk Import and Bulk Assignment remain separate and unchanged by OCR.

## Security reminders
- Never commit `storage/app/google/*.json`
- Never expose credentials, operation names, or GCS URIs in API responses, logs, or Blade/JS
- Preview/download require auth + `ocr.download` / `ocr.view`
- Keep GCS buckets private (no public ACL)
