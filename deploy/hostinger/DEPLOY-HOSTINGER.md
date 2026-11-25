# Put Sufra GroupOps on Hostinger

This zip is ready to run: the PHP libraries (`vendor/`) are included, so you do **not** need Composer or SSH.
You need a Hostinger plan with PHP + MySQL (Premium, Business or Cloud) and a domain or subdomain.

What is inside the zip:

| File / folder | What it is |
|---|---|
| `app/ config/ public/ resources/ routes/ vendor/ …` | the web panel + API (Laravel) |
| `.htaccess` | sends every visit into `public/`, forces HTTPS, hides `.env` |
| `.env` | settings — **you fill in the database and your domain** (step 4) |
| `database/sufra-groupops-database.sql` | the database with the demo company (import it in step 3; also in the repository at `backend/database/`) |
| `public/downloads/sufra-groupops.apk` | the Android app (the "Download Android app" button) |

---

## 1. PHP version

hPanel → **Websites → Manage → Advanced → PHP Configuration** → choose **PHP 8.3** (or 8.4) → Save.
(On the *PHP extensions* tab, `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `intl` and `zip` must be ticked — they are by default.)

## 2. Upload the files

1. hPanel → **Files → File Manager** → open **public_html** (for a subdomain: `public_html/<subdomain>` or the folder hPanel shows for it).
2. Delete Hostinger's default `default.php` / `index.html` if they are there.
3. **Upload** `sufra-groupops-hostinger.zip`, right-click it → **Extract** → extract into the same folder (not a sub-folder).
   You should now see `app`, `public`, `vendor`, `.htaccess`, `.env`, `artisan` … directly inside `public_html`.
4. Delete the zip file.

> Hidden files: if you don't see `.env` and `.htaccess`, click the ⚙ (settings) in File Manager → *Show hidden files*.

## 3. Database

1. hPanel → **Databases → MySQL Databases** → create a database + user + password. Write down the three names
   (they look like `u123456789_sufra`).
2. Click **Enter phpMyAdmin** next to the new database → **Import** → choose
   `public_html/database/sufra-groupops-database.sql` (download it from File Manager first, or use the copy next to the zip) → **Go**.
3. After the import, delete `database/sufra-groupops-database.sql` from the server.

## 4. Settings (`.env`)

In File Manager, right-click **`.env`** → Edit, and change:

```
APP_URL=https://your-domain.com          ← your real address, no / at the end
DB_DATABASE=u123456789_sufra             ← from step 3
DB_USERNAME=u123456789_sufra
DB_PASSWORD=the-database-password
```

Save. Do **not** change `APP_KEY` (it is already generated for you) and keep `APP_DEBUG=false`.

## 5. HTTPS

hPanel → **Security → SSL** → install the free SSL for the domain. The app forces HTTPS
(needed for secure logins and for the POS to reopen without network).

## 6. Scheduler (reminders, alerts, auto check-out …)

hPanel → **Advanced → Cron Jobs** → *Custom*, **every minute** (`* * * * *`), command:

```
/usr/bin/php /home/u123456789/domains/your-domain.com/public_html/artisan schedule:run
```

(Replace `u123456789` and `your-domain.com` with yours — the full path is shown at the top of File Manager.)

## 7. Open it

Go to `https://your-domain.com` → log in as **`SA-0001`**, password **`123456`**.

Do this immediately:

1. **Change the password** of `SA-0001` (top right → profile). All demo accounts use `123456`.
2. **Company settings → Attendance → TEST MODE: off** (the demo lets anyone check in from anywhere).
3. **Company settings → Mobile app** → server address = `https://your-domain.com` (the app and the **table QR codes** use it; print the QR codes after this, from *POS → Table QR codes*).
4. **Company settings → Logo & bill** → upload your logo.
5. Replace the demo people, branches and menu with yours (Employees, Locations, Sale items, Raw materials) —
   or keep them while you try it out.

Phones: open `https://your-domain.com/app` on the phone → download → install → in the app, *Server address* =
`https://your-domain.com`.

---

## Updating later

Upload the new zip and extract it over the old files, **but keep your `.env`** (don't overwrite it) and the
`public/storage` folder (your uploaded logo and photos). If the update has database changes, run once via
SSH: `php artisan migrate --force` (hPanel → Advanced → SSH Access), or ask for an SQL update file.

## Something wrong?

| You see | Fix |
|---|---|
| **500 / blank page** | open `storage/logs/laravel.log` in File Manager; check the DB lines in `.env`; folders `storage` and `bootstrap/cache` need permission 755 |
| **"No application encryption key"** | `APP_KEY` in `.env` was removed — put the original line back |
| **"419 Page expired" on login** | `APP_URL` must be the exact `https://` address you open; clear the browser cookies |
| **Photos / logo don't show** | `UPLOADS_IN_PUBLIC=true` must be in `.env`; the folder `public/storage` must exist |
| **Reminders never arrive** | the cron job of step 6 is missing or has the wrong path |
| **GitHub deploy: "530 Login incorrect"** | the FTP username/password in GitHub secrets are wrong: copy the FTP username from hPanel → Files → FTP Accounts and set a new FTP password there |
| **Phone app can't connect** | in the app use `https://your-domain.com` (no `/api`, no port) |

---

## Automatic deploy from GitHub (instead of uploading the zip)

Every push to `main` on GitHub uploads the web panel into `public_html` by FTP
(workflow: `.github/workflows/deploy.yml`). The first upload takes a while (≈7,000 files);
after that only changed files are sent. Your uploaded logo/photos (`public/storage`) and the APK
(`public/downloads`) on the server are never deleted.

1. hPanel → **Files → FTP Accounts**: note the FTP host (e.g. `ftp.your-domain.com` or an IP), username and password.
2. GitHub → your repository → **Settings → Secrets and variables → Actions → New repository secret**, add:

   | Secret | Value |
   |---|---|
   | `FTP_SERVER` | FTP host |
   | `FTP_USERNAME` | FTP user (e.g. `u123456789.your-domain.com`) |
   | `FTP_PASSWORD` | FTP password |
   | `FTP_SERVER_DIR` | *(optional)* website folder as the FTP login sees it — default `/public_html/` |

3. Database + `.env`, either:
   * **Secrets (recommended):** also add `APP_KEY` (any `base64:…` key — run `php artisan key:generate --show` on your PC),
     `APP_URL` (`https://your-domain.com`), `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. Each deploy then writes `.env` for you.
   * **or by hand:** create `public_html/.env` once in File Manager from `deploy/hostinger/env.hostinger` in the repository.
4. Import the demo database once: phpMyAdmin → Import → `backend/database/sufra-groupops-database.sql` from the repository.
5. *(optional)* Database updates after each deploy: add `SSH_HOST`, `SSH_USERNAME`, `SSH_PASSWORD`, `SSH_PORT` (`65002`)
   and `SSH_APP_DIR` (e.g. `domains/your-domain.com/public_html`) from hPanel → Advanced → SSH Access.
   Without them, new database changes need `php artisan migrate --force` once by SSH.
6. GitHub → **Actions** → *Deploy to Hostinger* → **Run workflow** (or just push to `main`).
