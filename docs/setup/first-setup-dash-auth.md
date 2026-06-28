# First Setup: Dashboard & Auth

After a clean `startup-guide.md` install, this guide bootstraps the
setup dashboard and a Plex-Shell-style auth layer: a single-owner
claim flow, then token-gated access to the admin surface.

No framework. No dependencies beyond PHP and the kernel DB.

---

## Auth model

Living Software uses a **single-owner, token-gated** auth model for v0:

```
  First visitor claims ownership  →  owner token written to runtime_state
  All subsequent admin requests   →  Bearer token checked against runtime_state
  Public record reads             →  no auth (capability-gated per record)
```

This mirrors the Plex-Shell pattern: the first person to hit `/setup`
with the correct `LS_AUTH_SECRET` becomes the owner. After that,
`/setup` is permanently closed.

Multi-user and OAuth are layer 03 capabilities added later, not
complexity in the kernel.

---

## Step 1 — Run the setup endpoint (first time only)

Open in browser:
```
https://yourdomain.com/setup
```

You will see the setup dashboard (served by `public/index.php` when
`runtime_state.owner_token IS NULL`).

Enter your `LS_AUTH_SECRET` to claim ownership. The endpoint:

1. Verifies the secret matches `LS_AUTH_SECRET` in `.env`
2. Generates a secure owner token: `bin2hex(random_bytes(32))`
3. Writes it to `runtime_state.owner_token`
4. Sets `runtime_state.setup_complete = 1`
5. Returns the token **once** in the response — copy it immediately

```bash
# Equivalent via curl:
curl -X POST https://yourdomain.com/setup \
  -H 'Content-Type: application/json' \
  -d '{"secret": "your-LS_AUTH_SECRET"}'
```

Response:
```json
{
  "status": "claimed",
  "owner_token": "a3f9...c2d1",
  "message": "Copy this token. It will not be shown again."
}
```

Store the owner token somewhere safe (password manager, `.env.local`).
After this call, `/setup` returns 403 permanently.

---

## Step 2 — Access the admin dashboard

```
https://yourdomain.com/admin
```

All `/admin/*` routes require:
```
Authorization: Bearer <owner_token>
```

The dashboard is a single-page record browser. On first load it shows:

```
┌─────────────────────────────────────────────────────────┐
│  Living Software — Admin                    [instance]  │
├──────────────┬──────────────────────────────────────────┤
│  Layers      │  Layer status                            │
│  Entities    │  ┌──────────────┬────────┐               │
│  Schemas     │  │ 00_kernel    │  OK  ✓ │               │
│  Carriers    │  │ 01_protocol  │  OK  ✓ │               │
│  Capabilities│  │ 02_surface   │  OK  ✓ │               │
│  Runtime     │  │ 03_capabilities│ OK ✓ │               │
│  Sessions    │  └──────────────┴────────┘               │
│              │                                          │
│              │  Runtime state                           │
│              │  adopted_commit:  abc1234                │
│              │  deploy_method:   git                    │
│              │  last_deploy:     2026-06-28 21:00        │
│              │  setup_complete:  1                      │
└──────────────┴──────────────────────────────────────────┘
```

---

## Step 3 — Create your first ontology seed records

From the dashboard or via API, create the root entity types for your
instance. Suggested seed for a personal instance:

```bash
# Create entity type: Note
curl -X POST https://yourdomain.com/api/entities \
  -H 'Authorization: Bearer <owner_token>' \
  -H 'Content-Type: application/json' \
  -d '{
    "type": "schema_def",
    "label": "Note",
    "metadata_json": {
      "fields": [
        {"name": "title",   "type": "string",   "required": true},
        {"name": "body",    "type": "text"},
        {"name": "tags",    "type": "string[]"}
      ]
    }
  }'

# Create entity type: Template
curl -X POST https://yourdomain.com/api/entities \
  -H 'Authorization: Bearer <owner_token>' \
  -H 'Content-Type: application/json' \
  -d '{
    "type": "schema_def",
    "label": "Template",
    "metadata_json": {
      "fields": [
        {"name": "title",        "type": "string",  "required": true},
        {"name": "body_type",    "type": "enum",    "values": ["html","markdown","json"]},
        {"name": "body",         "type": "text"},
        {"name": "transform_chain", "type": "string[]"}
      ]
    }
  }'
```

These become the first rows in your `entities` table with `type = schema_def`.
All subsequent user records reference these definitions.

---

## Step 4 — Create a public HTML template (first live record)

```bash
curl -X POST https://yourdomain.com/api/entities \
  -H 'Authorization: Bearer <owner_token>' \
  -H 'Content-Type: application/json' \
  -d '{
    "type": "Template",
    "label": "Hello World",
    "metadata_json": {
      "body_type": "html",
      "body": "<h1>{{title}}</h1><p>{{body}}</p>",
      "public": true
    }
  }'
```

The record is now accessible at:
```
https://yourdomain.com/r/{entity_id}
```

The surface layer evaluates `body_type = html`, applies the
`transform_chain` (fill fields → render), and returns the fragment.

---

## Auth token rotation

To rotate the owner token:

```bash
curl -X POST https://yourdomain.com/admin/rotate-token \
  -H 'Authorization: Bearer <current_owner_token>'
```

Response returns the new token once. The old token is immediately revoked.

---

## Adding a second user (capability)

Multi-user auth is a layer 03 capability, not built into the kernel.
Once you want it:

1. Create a `SPIKE-NNNN-multi-user-auth-strategy.md` to define the model
2. Write `ADR-NNNN-multi-user-auth.md` once the spike closes
3. Add `capabilities/user_auth.json` + `capabilities/user_auth.php`
4. CI verifies the capability descriptor
5. cron.php adopts the new capability

This is the pattern for all auth expansion: no changes to layers 00–02,
only additive capability records in layer 03.

---

## Dashboard local development

```bash
# PHP built-in server (no Apache/Nginx needed for dev)
cd living-software
php -S localhost:8080 -t public/

# In another terminal, run the golden tests
php tests/golden.php
```

The setup endpoint at `http://localhost:8080/setup` works identically
in local dev. Use a throwaway secret for local testing.
