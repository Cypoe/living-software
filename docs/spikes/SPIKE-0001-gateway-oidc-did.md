# SPIKE-0001 — API Gateway, DB Connection, Storage, OIDC Broker, DID Store

**Status:** open
**Author:** Fabian
**Date:** 2026-06-29
**Verdict deadline:** 2026-07-13
**Possible verdicts:** `adopt` | `reject` | `defer`

---

## Context

Living Software needs decisions on four infrastructure seams, each of which
is an implementation of the invariant protocol — not part of the kernel:

1. **API gateway / PHP runtime** — how requests enter the system
2. **DB / storage connection** — what backs the carrier layer beyond SQLite
3. **OIDC broker** — how identity tokens are issued and verified
4. **DID store** — how the system holds and resolves decentralised identifiers

All four are Layer 03 capabilities. The kernel (Layer 00) is always SQLite WAL.
Everything here is about the surface an instance *chooses* to run on.

---

## Option Matrix

### 1. API Gateway / PHP Runtime

| Option | Description | Fits LS? | Notes |
|---|---|---|---|
| **PHP-FPM + Nginx/Apache** | Classic CGI-style, one process per request pool | ✅ best default | Zero deps, works on any shared host or VPS. Cron loop is separate. |
| **FrankenPHP** | Go binary embedding PHP, HTTP/3, worker mode, auto-HTTPS | ✅ strong for own server | Worker mode keeps kernel DB handle alive between requests (no re-open per request). Single binary deploy. Caddy-based auto-TLS. |
| **RoadRunner** | Go app server, persistent PHP workers, plugin system | ⚠️ overkill | Best for high-throughput APIs. Adds config complexity. Plugin system is powerful but unnecessary for LS's self-contained model. |
| **Swoole / OpenSwoole** | Async PHP, coroutines | ❌ avoid | Breaks many PHP extensions, complex debugging, not needed at LS scale. |
| **Caddy + PHP-FPM** | Caddy as reverse proxy, PHP-FPM as handler | ✅ good middle path | Auto-HTTPS without FrankenPHP complexity. Simple `Caddyfile`. |

**Recommendation:** `PHP-FPM + Nginx` as default (shared-host/VPS zero-config).  
`FrankenPHP worker mode` as opt-in upgrade for own-server instances — enables persistent DB handle, HTTP/3, and single-binary deploys.  
Both are supported via the `deploy_method` runtime_state record; router.php is agnostic.

---

### 2. DB Connection / Storage

| Option | Description | Fits LS? | Notes |
|---|---|---|---|
| **SQLite WAL (kernel default)** | Single file, WAL mode, FTS5, JSON1 | ✅ kernel | Always present. 128 MB mmap, 8 MB cache. WAL allows concurrent readers + one writer. Sufficient for single-instance up to ~50 concurrent users. |
| **Turso (libSQL)** | SQLite-compatible cloud DB with embedded replica | ✅ interesting | Embedded replica means local reads (sub-ms), async sync to cloud. PHP driver via HTTP API. Good for multi-instance sync without gossip. |
| **PlanetScale / Neon (serverless MySQL/PG)** | Managed serverless SQL | ⚠️ conditional | Useful if instance is purely serverless (no cron loop). Breaks the self-hosting ethos unless explicitly configured. |
| **Nextcloud Tables (WebDAV)** | Record storage via Nextcloud's Tables API | ✅ Layer 03 | Already modeled as `WebDavAdapter`. Good for user-owned data sovereignty. Not a kernel replacement — a carrier backend. |
| **Local filesystem (files as carriers)** | Files on disk, LocalFsAdapter | ✅ default carrier | Already implemented. Works everywhere. |
| **S3 / R2 / MinIO** | Object storage for large carriers (blobs, media) | ✅ Layer 03 | Implement as `S3Adapter implements StorageAdapter`. Locator scheme: `s3://bucket/key`. |

**Recommendation:**  
- Kernel DB: always SQLite WAL (no change)  
- Multi-instance / cloud sync: Turso embedded replica (one ADR when needed)  
- Large carriers: S3Adapter (implement as capability when needed)  
- User-owned: WebDavAdapter (already exists, wire to Nextcloud)

---

### 3. OIDC Broker

The current auth model is a single owner token (Bearer, stored in runtime_state).
That is correct for a single-user instance. Multi-user OIDC is a Layer 03 capability.

| Option | Description | Fits LS? | Notes |
|---|---|---|---|
| **Keycloak** | Full enterprise OIDC/OAuth2 IdP | ⚠️ heavy | ~512 MB RAM minimum. Too heavy for a single VPS instance. Good if running in an org that already has Keycloak. |
| **Authelia** | Lightweight SSO + 2FA proxy | ✅ good fit | ~20 MB RAM. Supports OIDC, Traefik/Nginx integration. Config-file driven. Acts as IdP for LS. |
| **Authentik** | Modern IdP, flows, SCIM, LDAP | ✅ good fit | More UI than Authelia. ~200 MB. Better for managing multiple users and apps. |
| **Zitadel** | Cloud-native OIDC, SCIM, DID-friendly | ✅ strong candidate | Self-hostable, Go binary (~100 MB). Native OIDC server, supports custom claims, has a PHP SDK. DID-aware roadmap. |
| **PHP-native JWT verify only** | No IdP — just verify JWTs from any trusted issuer | ✅ minimal | For single-user + GitHub/Google as IdP. `firebase/php-jwt` or `web-token/jwt-framework`. No self-hosted infra needed. |
| **Pocket ID** | Minimal OIDC IdP, SQLite, single binary | ✅ best minimal fit | ~15 MB RAM. Passkey-first. SQLite backend — philosophically aligned with LS. Self-contained like LS itself. |

**Recommendation:**  
- Single user: current Bearer token (no change)  
- Multi-user minimal: **Pocket ID** — SQLite-backed, passkey-first, single binary, philosophically identical to LS  
- Multi-user enterprise: **Zitadel** — best DID alignment, OIDC server, PHP SDK  
- Both are Layer 03 capabilities registered as entities, not kernel concerns

---

### 4. DID Store

The `identities` table already stores `did:key`, `did:web`, `did:peer` strings
and their DID documents. The question is: what PHP library resolves and creates them?

| Option | Description | Fits LS? | Notes |
|---|---|---|---|
| **did:key (manual)** | Generate Ed25519 keypair, encode as multibase, no resolver needed | ✅ simplest | No external deps. Key is self-certifying. Works offline. PHP: `sodium_crypto_sign_keypair()` + multibase encoding. |
| **did:web** | DID document served at `https://domain/.well-known/did.json` | ✅ natural fit | LS already serves HTTP. A `did:web` for the instance is a single carrier record of type `did_document` served at the well-known path. Zero extra deps. |
| **did:peer** | Pairwise DIDs, no registry | ✅ for p2p gossip | Good for instance-to-instance trust. `did:peer:2` encodes keys + services in the DID string itself. PHP: encode manually. |
| **Verifiable Credentials PHP** | `web-token/jwt-framework` + manual VC envelope | ✅ compose | JWTs as VCs. LS can issue credentials as carrier records signed with the instance DID. |
| **walt.id SSI Kit** | Full SSI stack, DID + VC + EBSI | ❌ overkill | JVM-based. Too heavy. |
| **PHP DID library (`affinidi/did-php` etc.)** | Various community PHP DID libs | ⚠️ check maturity | Most are unmaintained. Better to implement the three methods above manually using `sodium` which ships with PHP 8. |

**Recommendation:**  
Implement three DID methods natively using PHP `sodium` extension (always available PHP 8+):

```
did:key  — Ed25519 keypair via sodium_crypto_sign_keypair()
           multibase-encode public key → z{base58btc}
           DID doc assembled in PHP, stored as carrier record

did:web  — DID doc served at /r/{did_doc_entity_id} mapped to
           /.well-known/did.json via a rewrite rule
           Updated via normal record PUT

did:peer — For gossip trust: did:peer:2 self-describing string
           Encode numalgo-2 with Ed25519 key + service endpoints
```

All three DID documents are stored as carrier records of type `did_document`.
The `identities` table holds the keypair metadata (NOT the private key inline —
private keys live in a separate encrypted file carrier, locator in `identities.metadata_json`).

---

## Summary Decision Table

| Seam | Default (now) | Upgrade path |
|---|---|---|
| PHP runtime | PHP-FPM + Nginx | FrankenPHP worker mode |
| DB | SQLite WAL | Turso embedded replica |
| Carrier storage | Local FS + WebDAV | S3Adapter |
| OIDC | Bearer token | Pocket ID → Zitadel |
| DID | did:key (sodium) | did:web + did:peer |

---

## Next Steps

- [ ] ADR-0002: Adopt FrankenPHP worker mode as opt-in runtime
- [ ] ADR-0003: did:key + did:web native implementation
- [ ] ADR-0004: Pocket ID integration as Layer 03 capability
- [ ] SPIKE-0002: Turso embedded replica vs SQLite WAL for multi-instance sync
- [ ] Implement `capabilities/did_key.php` (sodium keygen + DID doc assembly)
- [ ] Implement `capabilities/oidc_verify.php` (JWT verify against JWKS endpoint)
- [ ] Add `/.well-known/did.json` route to router.php
