# Startup Guide

From zero to a running Living Software instance in one sitting.

---

## Prerequisites

| Requirement | Minimum version | Notes |
|---|---|---|
| PHP | 8.1 | CLI + FPM or mod_php |
| SQLite | 3.35 | bundled with PHP's `pdo_sqlite` |
| Web server | Apache 2.4 or Nginx 1.18 | any that can serve PHP |
| Git | 2.x | for self-deploy via git pull |
| cURL | any | PHP ext, used by cron.php |
| GitHub account | — | for CI, label lifecycle, token |

Optional but recommended for SSH/SFTP deploy:
- SSH key pair on the server
- `rsync` installed

---

## Step 0 — Fork or clone

```bash
# Option A: clone directly (you own the server)
git clone https://github.com/Cypoe/living-software.git
cd living-software

# Option B: fork first on GitHub, then clone your fork
# This lets you push ADRs/Plans/Sessions back to your own repo
git clone https://github.com/YOUR_USERNAME/living-software.git
cd living-software
```

---

## Step 1 — Configure the environment

```bash
cp .env.example .env
```

Open `.env` and fill in:

```ini
# Required
LS_ROOT=/var/www/living-software   # absolute path to this directory
LS_GH_OWNER=Cypoe                  # GitHub repo owner
LS_GH_REPO=living-software         # GitHub repo name
LS_GH_TOKEN=ghp_xxxxxxxxxxxx       # Personal access token (repo + issues scope)
LS_DEPLOY_METHOD=git               # git | ftp | sftp

# Required for auth (set a strong random string)
LS_AUTH_SECRET=change-me-32-chars-minimum

# Optional — notify endpoint for candidate issues
# LS_NOTIFY_URL=https://yourdomain.com/notify

# Optional — FTP credentials if LS_DEPLOY_METHOD=ftp
# LS_FTP_HOST=
# LS_FTP_USER=
# LS_FTP_PASS=
# LS_FTP_PATH=/public_html
```

Generate `LS_AUTH_SECRET`:
```bash
php -r "echo bin2hex(random_bytes(24)) . PHP_EOL;"
```

---

## Step 2 — Initialise the kernel database

```bash
php kernel/init.php
```

This runs `kernel/schema.sql` against a fresh `kernel.db`, seeds the
`runtime_state` record, and writes the instance identity entity.

Verify:
```bash
php layers/00_kernel/verify.php
# expected: [OK] kernel layer verified
```

---

## Step 3 — Run all layer verifications

```bash
php layers/00_kernel/verify.php
php layers/01_protocol/verify.php
php layers/02_surface/verify.php
php layers/03_capabilities/verify.php
```

All four must print `[OK]` before continuing.

---

## Step 4 — Point your web server at public/

### Apache

```apache
<VirtualHost *:80>
  ServerName yourdomain.com
  DocumentRoot /var/www/living-software/public
  <Directory /var/www/living-software/public>
    AllowOverride All
    Require all granted
  </Directory>
</VirtualHost>
```

Ensure `mod_rewrite` is enabled (`a2enmod rewrite`).

### Nginx

```nginx
server {
  listen 80;
  server_name yourdomain.com;
  root /var/www/living-software/public;
  index index.php;

  location / {
    try_files $uri $uri/ /index.php?$query_string;
  }

  location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.1-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    include fastcgi_params;
  }
}
```

---

## Step 5 — Bootstrap GitHub labels

```bash
LS_GH_TOKEN=ghp_xxx php scripts/bootstrap_labels.php
```

This creates the `candidate`, `admissible`, `adopted`, `adr`, `plan`,
`spike` labels on the GitHub repo.

---

## Step 6 — Add the cron heartbeat

```bash
crontab -e
```

Add:
```
* * * * * php /var/www/living-software/public/cron.php >> /var/log/ls-cron.log 2>&1
```

`cron.php` runs every minute. It:
1. Checks GitHub for admissible commits
2. Pulls / deploys if a newer admissible commit exists
3. Runs layer verification post-deploy
4. Restarts PHP-FPM if verification passes
5. Opens a `candidate` Issue if verification fails

---

## Step 7 — First health check

```bash
curl -s https://yourdomain.com/health | python3 -m json.tool
```

Expected response:
```json
{
  "status": "ok",
  "instance": "my-instance",
  "adopted_commit": "abc1234",
  "layers": {
    "00_kernel": "ok",
    "01_protocol": "ok",
    "02_surface": "ok",
    "03_capabilities": "ok"
  }
}
```

---

## Step 8 — First-setup dashboard and auth

See [docs/setup/first-setup-dash-auth.md](first-setup-dash-auth.md).

---

## Spawn a second instance

Once the primary instance is running:

```bash
php spawn/spawn.php \
  --target=/var/www/staging \
  --name=staging \
  --deploy-method=git
```

The spawned instance gets a blank ontology and its own identity record.
It can gossip-merge with the primary later.

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `verify.php` prints `[FAIL] kernel.db not found` | `init.php` not run | `php kernel/init.php` |
| `/health` returns 404 | Web server docroot wrong | Point to `public/`, not repo root |
| cron.php silent | Wrong path in crontab | Use absolute path; test with `php /abs/path/cron.php` |
| `candidate` issues opening on every cron tick | Layer verify failing post-deploy | Check `/var/log/ls-cron.log` for PHP errors |
| GitHub token 401 | Token missing `repo` + `issues` scope | Regenerate with correct scopes |
