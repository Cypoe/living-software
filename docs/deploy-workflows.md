# Living Software — Deploy Workflow Documentation

## Overview

Two optional GitHub Actions deploy workflows ship with Living Software:
`deploy_ftp.yml` and `deploy_sftp.yml`. Both are **trigger-based** — they fire
only after the `Living Software CI` workflow completes successfully on `main`.
Neither is the primary deploy mechanism; that role belongs to `cron.php` running
on the host. They are the “push” alternative for hosts that cannot run cron or
reach GitHub’s API directly.

---

## The Secret-Visibility Problem

GitHub Actions prevents comparing secrets directly in `if:` expressions. The
following pattern **looks correct but does not work**:

```yaml
if: secrets.FTP_HOST != ''
```

GitHub redacts secret values during expression evaluation for security reasons.
The expression always resolves to `true` regardless of whether the secret is set,
so the job runs anyway — then fails when `curl` or `rsync` receives an empty
argument.

The correct pattern is to expose the secret as an environment variable inside a
`run:` step and write a skip flag to `GITHUB_OUTPUT`:

```yaml
- name: Check secrets configured
  id: check
  env:
    FTP_HOST: ${{ secrets.FTP_HOST }}
  run: |
    if [ -z "$FTP_HOST" ]; then
      echo "secrets not configured, skipping"
      echo "skip=true" >> "$GITHUB_OUTPUT"
    else
      echo "skip=false" >> "$GITHUB_OUTPUT"
    fi

- name: Deploy
  if: steps.check.outputs.skip != 'true'
  ...
```

The job always starts (GitHub cannot skip a job based on a secret value), but
every subsequent step is gated on the check step’s output. The deploy step is
skipped cleanly and the job finishes green.

---

## deploy_ftp.yml

### Purpose

Uploads all non-excluded files to a remote host over FTP using `curl
--ftp-create-dirs`. Suitable for shared hosting where only FTP is available and
no SSH access exists.

### Trigger

```yaml
on:
  workflow_run:
    workflows: ["Living Software CI"]
    types: [completed]
    branches: [main]
```

The job’s `if:` condition is `github.event.workflow_run.conclusion ==
'success'`. If CI failed, the job is skipped before any secrets are tested.

### Required secrets

| Secret | Description |
|---|---|
| `FTP_HOST` | FTP hostname, e.g. `ftp.yourdomain.com` |
| `FTP_USER` | FTP username |
| `FTP_PASS` | FTP password |
| `FTP_REMOTE_DIR` | Remote path, e.g. `/public_html/living-software` |

### Optional secrets

| Secret | Description |
|---|---|
| `INSTANCE_UPDATE_URL` | Base URL of the running instance, e.g. `https://yourdomain.com` |
| `INSTANCE_UPDATE_TOKEN` | Shared secret matching `LS_UPDATE_TOKEN` in `.env` |

If both notify secrets are set, the workflow sends a `POST /runtime/notify` to
the instance after deploy so it records the new `adopted_commit` in
`runtime_state` immediately, without waiting for the next cron tick.

### What is excluded

- `.git/` — version control metadata
- `.env` — instance-local secrets
- `kernel/kernel.db` — live database, must survive deploy intact
- `kernel/heartbeat.json` — runtime ephemeral state

### Skip behaviour when secrets are absent

The `check` step runs first and writes `skip=true` to `GITHUB_OUTPUT` when any
required FTP secret is empty. Every subsequent step carries `if:
steps.check.outputs.skip != 'true'`. Missing secrets → skipped steps → green
job.

---

## deploy_sftp.yml

### Purpose

Pushes files via `rsync` over SSH. Delta-only transfers make subsequent deploys
fast — only changed files cross the wire. Requires SSH access to the remote host
and a deploy keypair.

### Trigger

Same `workflow_run` trigger as the FTP workflow.

### Required secrets

| Secret | Description |
|---|---|
| `SFTP_HOST` | Remote hostname, e.g. `yourdomain.com` |
| `SFTP_USER` | SSH username |
| `SFTP_SSH_KEY` | Full contents of the private key file, e.g. `~/.ssh/id_rsa` |
| `SFTP_REMOTE_DIR` | Remote path, e.g. `/var/www/living-software` |

### Optional secrets

| Secret | Description |
|---|---|
| `SFTP_PORT` | SSH port, defaults to `22` if unset |
| `INSTANCE_UPDATE_URL` | Same as FTP workflow |
| `INSTANCE_UPDATE_TOKEN` | Same as FTP workflow |

### Key setup

Generate a dedicated deploy keypair — do not reuse a personal key:

```bash
ssh-keygen -t ed25519 -C "living-software-deploy" -f ~/.ssh/ls_deploy -N ""
```

Add the public key to `~/.ssh/authorized_keys` on the remote host:

```bash
cat ~/.ssh/ls_deploy.pub >> ~/.ssh/authorized_keys
```

Paste the contents of `~/.ssh/ls_deploy` (private key) as the `SFTP_SSH_KEY`
GitHub secret. The workflow writes it to a temp file, `chmod 600`, and passes it
to rsync via `-e "ssh -i ~/.ssh/deploy_key"`.

### rsync flags used

```
rsync -az --delete \
  --exclude='.git' \
  --exclude='.env' \
  --exclude='kernel/kernel.db' \
  --exclude='kernel/heartbeat.json'
```

`--delete` removes files on the remote that no longer exist in the repo. `-a`
preserves permissions and timestamps. `-z` compresses the transfer.

### Skip behaviour when secrets are absent

Identical pattern to FTP: `check` step writes to `GITHUB_OUTPUT`, all deploy
steps gated on its output. Absent secrets → skipped steps → green job.

---

## Choosing between cron.php and a workflow

| Factor | `cron.php` (pull) | Workflow (push) |
|---|---|---|
| Host requires | PHP + cron + outbound HTTPS | Nothing (GitHub runner does the work) |
| Deploy trigger | Every minute, polls GitHub | Immediately after CI passes |
| Delta transfers | No (full git reset) | rsync: yes / FTP: no |
| Works if host has no git | No | Yes (FTP or rsync) |
| Works if host has no SSH | Yes (git over HTTPS or cron only) | FTP only |
| `adopted_commit` tracking | Written by cron after deploy | Written by `/runtime/notify` ping |

Recommended combinations:

- **PHP shared hosting (FTP only):** `deploy_ftp.yml` as primary push +
  `cron.php` with `LS_DEPLOY_METHOD=ftp` as self-healing fallback.
- **VPS with SSH:** `deploy_sftp.yml` as primary push + `cron.php` with
  `LS_DEPLOY_METHOD=git` as fallback.
- **Cron only, no Actions:** `cron.php` with `LS_DEPLOY_METHOD=git` or `ftp`,
  no workflow secrets needed.

---

## The /runtime/notify endpoint

Both workflows optionally `POST` to `/runtime/notify` after a successful deploy:

```json
{
  "event": "deploy",
  "sha": "<head commit sha>",
  "method": "ftp"
}
```

The instance verifies `Authorization: Bearer <token>` against `LS_UPDATE_TOKEN`,
then writes the sha to `runtime_state.adopted_commit`. If the ping fails
(instance unreachable, wrong token), `|| true` ensures the workflow step still
passes — the instance self-corrects on the next cron tick.
