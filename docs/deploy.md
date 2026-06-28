# Deployment Guide

## Minimal PHP host setup

1. Upload `public/`, `kernel/`, `tests/`, `capabilities/` to your host.
2. Set the document root to `public/`.
3. Set environment variables:
   - `LS_REPO_OWNER` — GitHub owner (default: Cypoe)
   - `LS_REPO_NAME` — repo name (default: living-software)
   - `LS_BRANCH` — branch to track (default: main)
   - `LS_GH_TOKEN` — GitHub PAT with read:repo scope
   - `LS_UPDATE_TOKEN` — shared secret for `/runtime/notify`
4. Add a cron job: `* * * * * php /path/to/public/cron.php >> /var/log/living-software.log 2>&1`

## GitHub Secrets (for Actions)

- `INSTANCE_UPDATE_URL` — base URL of deployed instance (e.g. `https://yourdomain.com`)
- `INSTANCE_UPDATE_TOKEN` — must match `LS_UPDATE_TOKEN` on the server

## Self-update flow

1. Push to main triggers CI.
2. If CI passes, `notify_instance.yml` POSTs `{sha}` to `POST /runtime/notify`.
3. Server stores `pending_sha` in `runtime_state`.
4. `cron.php` ticks, fetches GitHub, verifies CI passed for that sha, adopts it.
5. `GET /runtime/status` shows `adopted_commit`.

## Rollback

Set `adopted_commit` in `runtime_state` back to the last known good SHA.
The system will stop self-updating until a newer admissible commit appears.
