# Deploy CRM on Hostinger

## Important: two different setups

| Where the app runs | DB_HOST in `.env` | Speed |
|--------------------|-------------------|-------|
| **Your Mac** (`php artisan serve`) | `127.0.0.1` + local `CRM-PROJECT` | Fast |
| **Hostinger server** (live site) | `localhost` + Hostinger DB | Fast |
| **Your Mac → Hostinger DB remotely** | `srv1999.hstgr.io` | **Very slow — causes 500 errors** |

Remote MySQL (`srv1999.hstgr.io`) is only for tools like MySQL Workbench from your PC.  
When the Laravel app is **on Hostinger**, always use `DB_HOST=localhost`.

---

## Fix: Hostinger Git deploy "Build failed"

If hPanel shows **Build failed** with root directory `public_html`, the built-in Git deploy is not configured for Laravel yet.

Laravel cannot run with only files dumped into `public_html`. You need the **full project** plus a symlink from `public_html` → `public`.

### Option A — SSH setup (recommended, one-time)

Ask your manager / hosting admin to run these in **hPanel → Advanced → SSH** (replace domain path):

```bash
cd ~/domains/YOUR-DOMAIN.com

# Remove default public_html so we can symlink it
rm -rf public_html

# Clone from GitHub (if not already done by hPanel)
git clone git@github.com:cacloud12-web/crm-project-.git .

# Install PHP dependencies (vendor/ is not in Git)
composer install --no-dev --optimize-autoloader

# Production environment
cp .env.hostinger.example .env
# Edit .env: set APP_URL, DB_HOST=localhost, DB credentials from hPanel
php artisan key:generate --force

# Database
php artisan migrate --force
php artisan db:seed --class=CrmUserSeeder --force

# Point web root to Laravel public folder
ln -s public public_html

# Permissions
chmod -R 775 storage bootstrap/cache
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

After this, **disable** hPanel auto-deploy to `public_html` (or use pull-only updates via SSH):

```bash
cd ~/domains/YOUR-DOMAIN.com
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Option B — hPanel Git panel settings

If you must use hPanel Git integration:

| Setting | Value |
|---------|-------|
| Repository | `cacloud12-web/crm-project-` |
| Branch | `main` |
| Install directory | Domain folder (e.g. `domains/your-domain.com/`) — **not** inside `public_html` |
| Build command | `bash scripts/hostinger-build.sh` |
| Web root | Symlink `public_html` → `public` (SSH, see Option A) |

**Do not** set repository "root directory" to `public_html` — that folder does not exist in the Git repo and the build fails in a few seconds.

### Production `.env` on server

```env
APP_URL=https://your-actual-domain.com
APP_ENV=production
APP_DEBUG=false
DB_HOST=localhost
DB_DATABASE=u636438798_crmproject
DB_USERNAME=u636438798_crmuser
DB_PASSWORD=your-password-from-hpanel
```

Default logins after seed:

| Email | Password |
|-------|----------|
| superadmin@ca.local | password |
| manager@ca.local | password |

---

## 1. Prepare files locally

```bash
cd crm-project
composer install --no-dev --optimize-autoloader
npm ci && npm run build   # if you use Vite assets
```

Do **not** upload `.env` from your Mac. Use `.env.hostinger.example` on the server.

---

## 2. Upload to Hostinger

**hPanel → Files → File Manager** (or FTP):

Upload the project **except**:
- `node_modules/`
- `.git/`
- local `.env`

Typical structure on shared hosting:

```
/home/u636438798/
  domains/your-domain.com/
    public_html/          ← web root (see step 3)
    crm-app/              ← Laravel project root (private, above public_html)
```

---

## 3. Point web root to `public/`

**Option A (recommended):** Move/copy contents of Laravel `public/` into `public_html/` and edit `public_html/index.php`:

```php
require __DIR__.'/../crm-app/vendor/autoload.php';
$app = require_once __DIR__.'/../crm-app/bootstrap/app.php';
```

**Option B:** hPanel → **Advanced → PHP Configuration** or domain settings — set document root to `crm-app/public` if your plan allows.

---

## 4. Create `.env` on the server

Copy `.env.hostinger.example` to `crm-app/.env` and set:

```env
APP_URL=https://your-actual-domain.com
DB_HOST=localhost
DB_DATABASE=u636438798_crmproject
DB_USERNAME=u636438798_crmuser
DB_PASSWORD=your-password-from-hpanel
```

Generate a new app key on the server:

```bash
php artisan key:generate
```

---

## 5. Run migrations & seed (SSH or hPanel Terminal)

```bash
cd ~/crm-app   # your Laravel root
php artisan migrate --force
php artisan db:seed --class=CrmUserSeeder --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Default logins after seed:

| Email | Password |
|-------|----------|
| superadmin@ca.local | password |
| manager@ca.local | password |

---

## 6. Permissions

```bash
chmod -R 775 storage bootstrap/cache
```

---

## 7. Import existing data (optional)

If you have data in local `CRM-PROJECT` MySQL, export and import via **phpMyAdmin** on Hostinger:

1. Local: export `CRM-PROJECT` database (SQL dump)
2. Hostinger hPanel → **phpMyAdmin** → import into `u636438798_crmproject`

---

## 8. PHP version

Use **PHP 8.2+** in hPanel → **Advanced → PHP Configuration**.

Required extensions: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`.

---

## Local development (keep on your Mac)

Keep `.env` on your Mac with:

```env
DB_HOST=127.0.0.1
DB_DATABASE=CRM-PROJECT
```

Develop locally, then deploy updated code to Hostinger when ready.
