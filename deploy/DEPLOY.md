# Deploying Skyledger (Module 4) on DigitalOcean

Plain PHP 8 + SQLite, no build step. Target: an Ubuntu droplet with nginx + PHP-FPM.
Everything below assumes the repo is cloned to `/var/www/signum-aviation` — the app lives at
the repo root, so it serves at the domain root (no extra URL path segment).

## Fast path (script)

```bash
sudo apt-get install -y git
sudo git clone https://github.com/CY-FusionETA/signum-aviation.git /var/www/signum-aviation
cd /var/www/signum-aviation
sudo bash deploy/setup.sh skyledger.fusioneta.com.my 8.3     # <domain> <php version>
sudo certbot --nginx -d skyledger.fusioneta.com.my           # HTTPS (required by Xero)
```

Then open `https://skyledger.fusioneta.com.my`, sign in with the password you set, and
connect Xero. That's it.

## Manual path (what the script does)

1. **Install runtime**
   ```bash
   sudo apt-get update
   sudo apt-get install -y php8.3-fpm php8.3-sqlite3 php8.3-curl php8.3-mbstring \
                           php8.3-zip php8.3-xml poppler-utils nginx
   ```
   `poppler-utils` provides `pdftotext` (LEON **PDF** import); `php-zip` + `php-xml` are
   needed for **XLSX** import.

2. **Config** — `cp config/config.sample.php config/config.php`, then set:
   - `app.base_url` = `https://your-domain` (no trailing slash)
   - `app.admin_password_hash` = `php -r "echo password_hash('yourpass', PASSWORD_DEFAULT);"`
   - leave `xero.*` empty — you'll enter Client ID/Secret in the UI (so you can point at
     any org without editing files).

3. **Database** — `php db/migrate.php` (creates `storage/skyledger.sqlite`).

4. **Permissions** — the web user must write `storage/` (SQLite WAL + uploads):
   ```bash
   sudo chown -R www-data:www-data storage && sudo chmod -R u+rwX storage
   ```

5. **nginx** — copy `deploy/nginx.conf.example` to `/etc/nginx/sites-available/skyledger`,
   edit `server_name`, `root` (must end in `/public`), and the `fastcgi_pass` socket to
   match your PHP version; symlink into `sites-enabled`, then
   `sudo nginx -t && sudo systemctl reload nginx`.

6. **HTTPS** — `sudo certbot --nginx -d your-domain`. Xero OAuth only allows plain `http`
   for `localhost`, so a live test needs a real certificate.

## Connect Xero (per organisation)

1. In the [Xero developer portal](https://developer.xero.com/app/manage) create/open an app.
2. Add the redirect URI **exactly**: `https://your-domain/xero/callback`.
3. In Skyledger → **Xero settings**, paste Client ID + Secret, Save, then **Connect to Xero**.
4. To test against a different org later, **Reconnect / switch org** — trips created in the
   old org re-create in the new one automatically.

## Updating a deployment

```bash
cd /var/www/signum-aviation && sudo git pull
php db/migrate.php                              # migrate is safe to re-run (CREATE IF NOT EXISTS)
sudo chown -R www-data:www-data storage
```

`config/config.php` and `storage/` are git-ignored, so a pull never clobbers your secrets
or database.

## Notes

- SQLite is single-file and fine for this workload. Back it up by copying
  `storage/skyledger.sqlite*` (include the `-wal`/`-shm` sidecars).
- If you host under a **subpath** instead of a subdomain, set `app.base_url` to the full
  subpath URL and point the nginx `location` accordingly.
