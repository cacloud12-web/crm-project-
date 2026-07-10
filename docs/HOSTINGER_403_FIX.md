# Hostinger 403 Fix — crm.caclouddesk.com

Verified on **2026-07-10** against the live site.

## Live probe results (evidence)

| URL | HTTP | Meaning |
|-----|------|---------|
| `https://crm.caclouddesk.com/` | **403** | No `index.php` at document root |
| `https://crm.caclouddesk.com/index.php` | **404** | Confirms no root `index.php` |
| `https://crm.caclouddesk.com/login` | **404** | Laravel routing not active at root |
| `https://crm.caclouddesk.com/public/` | **500** | PHP runs; Laravel boots from `public/` |
| `https://crm.caclouddesk.com/public/login` | **500** | Same Laravel error |
| `https://crm.caclouddesk.com/artisan` | **200** | **Security risk** — app root is web-exposed |
| `https://crm.caclouddesk.com/composer.json` | **200** | **Security risk** — app root is web-exposed |
| `https://crm.caclouddesk.com/.env` | **404** | `.env` not directly exposed (good) |

Server headers: `platform: hostinger`, `x-turbo-charged-by: LiteSpeed`, PHP **8.3.30**.

## Root cause of 403 Forbidden

**Confirmed: incorrect document root / deployment structure.**

Hostinger Git auto-deploy placed the **entire Laravel repository** inside `public_html`:

```
public_html/                 ← Apache document root
├── app/
├── artisan                  ← currently downloadable!
├── bootstrap/
├── composer.json            ← currently downloadable!
├── config/
├── public/
│   ├── index.php            ← Laravel entry (only works at /public/ URL)
│   └── .htaccess
├── storage/
├── vendor/
└── (no index.php here)      ← Apache finds nothing → 403 Forbidden
```

Apache/LiteSpeed serves `public_html/`. There is **no `index.php` at that level**.  
Directory listing is disabled (`Options -Indexes` in Laravel's `public/.htaccess`), so the server returns **403 Forbidden** — not a Laravel error.

This is **not** caused by missing `.env`, wrong `APP_URL`, or storage permissions. Those cause **500** after Laravel boots (seen at `/public/`).

## Root cause of 500 at /public/

Laravel **does** boot at `/public/` (Symfony error page returned). After fixing the 403, run the SSH steps below to clear the 500 — typically:

1. Missing or invalid `APP_KEY`
2. `storage/` or `bootstrap/cache/` not writable
3. Database credentials wrong in server `.env`
4. Stale `bootstrap/cache/config.php` from a failed build

## Repository verification

| Item | In repo | On server (inferred) |
|------|---------|----------------------|
| `public/index.php` | Yes | Yes (works at `/public/`) |
| `public/.htaccess` | Yes | Yes |
| `vendor/` | Gitignored | Yes (composer install ran) |
| `artisan` | Yes | Yes (exposed — bad) |
| Root `index.php` | **Added in this fix** | Was missing → 403 |
| Root `.htaccess` | **Added in this fix** | Was missing → no routing/security |
| `.env` | Gitignored | Must exist on server (created by build script) |
| `bootstrap/cache` writable | N/A locally | Must be 775 on server |
| `storage` writable | N/A locally | Must be 775 on server |

## Fix applied in repository

1. **`index.php`** (project root) — front controller when repo root = `public_html`
2. **`.htaccess`** (project root) — routes requests + blocks `artisan`, `vendor/`, `app/`, etc.
3. **`.env.hostinger.example`** — correct `APP_URL`, `file` session/cache, empty `APP_KEY`
4. **`scripts/hostinger-build.sh`** — `optimize:clear`, `key:generate`, `storage:link`, permissions

## Hostinger SSH checklist (run after redeploy)

```bash
cd ~/domains/crm.caclouddesk.com/public_html

# 1. Pull latest code (with root index.php + .htaccess)
git pull origin main

# 2. Dependencies
composer install --no-dev --optimize-autoloader

# 3. Environment
cp -n .env.hostinger.example .env   # skip if .env already exists
# Edit .env: confirm APP_URL=https://crm.caclouddesk.com and DB_* credentials

# 4. Laravel setup
php artisan key:generate --force
php artisan optimize:clear
php artisan migrate --force
php artisan db:seed --class=CrmUserSeeder --force
php artisan storage:link

# 5. Permissions
chmod -R 775 storage bootstrap/cache

# 6. Cache for production
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Verify success

```bash
curl -I https://crm.caclouddesk.com/
# Expect: HTTP 200 or 302 (not 403)

curl -I https://crm.caclouddesk.com/login
# Expect: HTTP 200

curl -I https://crm.caclouddesk.com/artisan
# Expect: HTTP 403 (blocked by root .htaccess)

curl -I https://crm.caclouddesk.com/up
# Expect: HTTP 200
```

## Long-term recommended structure (optional)

Ideal Hostinger layout — project **outside** `public_html`:

```
domains/crm.caclouddesk.com/
├── app/, vendor/, public/, artisan, .env ...
└── public_html → symlink to public/
```

```bash
cd ~/domains/crm.caclouddesk.com
rm -rf public_html
ln -s public public_html
```

Then set Git install directory to the domain folder, **not** inside `public_html`.

## Login after successful deploy

| Email | Password |
|-------|----------|
| superadmin@ca.local | password |
| manager@ca.local | password |
