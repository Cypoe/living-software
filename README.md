# Living Software

A self-hosting, protocol-algebraic substrate where **records are the only primitive**.

Every concept — a task, a deployed webpage, a CI rule, a user’s theory of mind — is a row in the `entities` table. The system represents, deploys, versions, and evolves itself using the same record algebra it exposes to users.

## Five Layers

```
04_ontology      ─ user records, templates, views          [instance-local]
03_capabilities  ─ deterministic transforms over records
02_surface       ─ HTTP router + cron self-deploy loop
01_protocol      ─ record algebra: select/filter/join/transform/project/render
00_kernel        ─ entities × schemas × carriers + runtime_state
```

See [docs/architecture.md](docs/architecture.md) and [docs/layers.md](docs/layers.md).

## Quick Start

```bash
# 1. Clone
git clone https://github.com/Cypoe/living-software.git
cd living-software

# 2. Configure
cp .env.example .env
# fill in LS_GH_TOKEN, LS_ROOT, LS_DEPLOY_METHOD

# 3. Point webserver docroot at public/
# Apache: DocumentRoot /path/to/living-software/public
# Nginx: root /path/to/living-software/public;

# 4. Add cron
echo '* * * * * php /path/to/living-software/public/cron.php >> /var/log/ls.log 2>&1' | crontab -

# 5. Verify
curl https://yourdomain.com/health
```

## Spawn a new instance

```bash
php spawn/spawn.php --target=/var/www/my-instance --name=my-instance
```

The spawned instance inherits the kernel, protocol, surface and capabilities
layers. It starts with a blank ontology and its own identity record.

## Bootstrap labels

```bash
LS_GH_TOKEN=ghp_xxx php scripts/bootstrap_labels.php
```

## Run layer verification

```bash
php layers/00_kernel/verify.php
php layers/01_protocol/verify.php
php layers/02_surface/verify.php
php layers/03_capabilities/verify.php
```

## Deploy options

| Method | How |
|---|---|
| `git` | `cron.php` runs `git fetch && git reset --hard` |
| `ftp` | `cron.php` uploads via PHP FTP extension, or GitHub Actions `deploy_ftp.yml` |
| `sftp` | GitHub Actions `deploy_sftp.yml` via rsync over SSH |

See [docs/deploy-workflows.md](docs/deploy-workflows.md).

## Label lifecycle

```
candidate → admissible → adopted
```

Running instances emit `candidate` Issues. CI promotes to `admissible`. Merge
advances `adopted_commit` in `runtime_state`.
