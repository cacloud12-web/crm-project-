# Deploy CRM on Hostinger

## Important: two different setups

| Where the app runs | DB_HOST in `.env` | Speed |
|--------------------|-------------------|-------|
| **Your Mac** (`php artisan serve`) | `127.0.0.1` + local `CRM-PROJECT` | Fast |
| **Hostinger server** (live site) | `localhost` + Hostinger DB | Fast |
| **Your Mac → Hostinger DB remotely** | `srv1999.hstgr.io` | **Very slow — do not use** |

Remote MySQL (`srv1999.hstgr.io`) is only for tools like MySQL Workbench from your PC.  
When the Laravel app is **on Hostinger**, always use `DB_HOST=localhost`.

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
