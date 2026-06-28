# Living Software

A self-hosting, protocol-algebraic substrate where **records are the only primitive**.

---

## The Scale-Free Triad

Everything in the system — a user's task, a deployed webpage, a CI rule,
a running instance, the system itself — is one of three things, at every scale:

```
        ╔═══════════════╗
        ║    ENTITY     ║  ← what a thing IS   (id, type, owner)
        ╚═══════╤═══════╝
                │ described by
        ╔═══════▼═══════╗
        ║    SCHEMA     ║  ← what shape it has (field definitions)
        ╚═══════╤═══════╝
                │ carried by
        ╔═══════▼═══════╗
        ║    CARRIER    ║  ← where its body lives (file, URL, DB row, blob)
        ╚═══════════════╝
```

This triad is **scale-free**: it applies at every level of the system.

```
  At the data level:
    entity  = a task record
    schema  = { title: string, done: bool }
    carrier = a row in kernel.db

  At the capability level:
    entity  = "webhook_out" capability
    schema  = { input: candidate_record, output: github_issue_ref }
    carrier = capabilities/webhook_out.php

  At the instance level:
    entity  = this running instance
    schema  = { adopted_commit, deploy_method, health_check_url }
    carrier = the server + kernel.db it runs on

  At the system level:
    entity  = Living Software itself
    schema  = the layer contracts (layers/00–04/layer.json)
    carrier = the GitHub repo + every spawned instance
```

The quine property: the schema table is itself an entity. The carrier table
stores its own definition as a carrier. The system describes itself using
itself — there is no external meta-level.

### The Dyad inside the Triad

Entity and Schema form the **core dyad** — the minimum needed to assert
that something exists and has a shape. Carrier is the optional third: without
it a record is abstract (a pure assertion). With it the record has a body
that can be fetched, rendered, or executed.

```
  dyad  alone  →  abstract record     (a concept, a type definition)
  triad         →  live record         (a file, a page, a running process)
```

A template entity with an `html` carrier is a webpage.
A capability entity with a `php` carrier is executable logic.
An instance entity with a `server` carrier is a running system.
The same algebra, the same three roles, at every scale.

### Recursive diagram

```
┌─ Living Software (entity) ──────────────────────────────────────┐
│  schema  = layer contracts                                       │
│  carrier = GitHub repo + instances                               │
│                                                                  │
│  ┌─ Instance (entity) ──────────────────────────────────────┐   │
│  │  schema  = deploy_config record                          │   │
│  │  carrier = server + kernel.db                            │   │
│  │                                                          │   │
│  │  ┌─ Capability (entity) ──────────────────────────────┐ │   │
│  │  │  schema  = { input_schema, output_schema }         │ │   │
│  │  │  carrier = capabilities/{id}.php                   │ │   │
│  │  │                                                    │ │   │
│  │  │  ┌─ Record (entity) ────────────────────────────┐ │ │   │
│  │  │  │  schema  = user-defined type definition      │ │ │   │
│  │  │  │  carrier = row in entities table             │ │ │   │
│  │  │  │                                              │ │ │   │
│  │  │  │  ┌─ Field value ──────────────────────────┐ │ │ │   │
│  │  │  │  │  entity  = the value itself            │ │ │ │   │
│  │  │  │  │  schema  = field type (string, ref…)   │ │ │ │   │
│  │  │  │  │  carrier = JSON in metadata_json col   │ │ │ │   │
│  │  │  │  └────────────────────────────────────────┘ │ │ │   │
│  │  │  └──────────────────────────────────────────────┘ │ │   │
│  │  └────────────────────────────────────────────────────┘ │   │
│  └──────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

Each box is an entity–schema–carrier triad. The nesting is not a hierarchy
of types — it is the **same pattern at different scales**. You can start
from any box and apply the full algebra to it.

---

## Five Layers

```
04_ontology      ─ user records, templates, views          [instance-local]
03_capabilities  ─ deterministic transforms over records
02_surface       ─ HTTP router + cron self-deploy loop
01_protocol      ─ record algebra: select/filter/join/transform/project/render
00_kernel        ─ entities × schemas × carriers + runtime_state
```

---

## Documentation

### Setup

| Doc | What it covers |
|---|---|
| [docs/setup/startup-guide.md](docs/setup/startup-guide.md) | Full step-by-step from zero to running instance |
| [docs/setup/first-setup-dash-auth.md](docs/setup/first-setup-dash-auth.md) | Claim ownership, bootstrap admin dashboard, first ontology seed records |

### Architecture

| Doc | What it covers |
|---|---|
| [docs/architecture.md](docs/architecture.md) | Full system architecture |
| [docs/layers.md](docs/layers.md) | Layer contracts and verification gates |
| [docs/gossip.md](docs/gossip.md) | Instance gossip-merge protocol |
| [docs/deploy-workflows.md](docs/deploy-workflows.md) | Git / FTP / SFTP deploy workflows and secret handling |

### Process

| Doc | What it covers |
|---|---|
| [docs/process/adr-pipeline.md](docs/process/adr-pipeline.md) | ADR, Plan, Session, Spike — types, state machines, templates, CI gates |

---

## Quick Start

```bash
# 1. Clone
git clone https://github.com/Cypoe/living-software.git
cd living-software

# 2. Configure
cp .env.example .env
# fill in LS_GH_TOKEN, LS_ROOT, LS_AUTH_SECRET, LS_DEPLOY_METHOD

# 3. Initialise the kernel DB
php kernel/init.php

# 4. Point webserver docroot at public/
# Apache: DocumentRoot /path/to/living-software/public
# Nginx:  root /path/to/living-software/public;

# 5. Add cron
echo '* * * * * php /path/to/living-software/public/cron.php >> /var/log/ls.log 2>&1' | crontab -

# 6. Verify
curl https://yourdomain.com/health

# 7. Claim ownership (first time only)
curl -X POST https://yourdomain.com/setup \
  -H 'Content-Type: application/json' \
  -d '{"secret": "your-LS_AUTH_SECRET"}'
```

Full walkthrough: [docs/setup/startup-guide.md](docs/setup/startup-guide.md)

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

## Process lifecycle

```
Spike → ADR → Plan → Sessions → CI green → admissible → adopted
```

Every non-trivial decision is a record. See [docs/process/adr-pipeline.md](docs/process/adr-pipeline.md).
