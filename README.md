# Maho on Fly.io

Deploy [Maho Commerce](https://mahocommerce.com) on [Fly.io](https://fly.io) with PostgreSQL (via [Neon](https://neon.tech)).

## Why SQLite → Postgres?

Maho installs to SQLite in seconds with zero infrastructure — perfect for Docker builds. But SQLite is single-writer, can't run on ephemeral containers, and doesn't scale.

The solution: **install to SQLite at build time, migrate to Postgres at boot time.**

This gives us:
- **Fast Docker builds** — SQLite install runs on any CI builder with no network DB
- **Production Postgres** — Neon serverless Postgres in the same region as Fly.io
- **Idempotent startup** — if the DB is already populated, migration is skipped (~1s boot)

### Why not install directly to Postgres?

Fly.io builds run on [Depot](https://depot.dev) in the US. A Maho install runs hundreds of SQL statements — doing that over a network connection to a Postgres instance in Sydney would be painfully slow and fragile. SQLite install is local, fast, and reliable.

### Why not Fly Postgres?

We tried `fly postgres-flex` (managed Postgres on Fly.io). On shared CPU, the DB was throttled so heavily that TTFB hit 3.6 seconds. Neon in the same region (Sydney) dropped that to ~0.46s.

### Why not pgloader?

pgloader uses cl-postgres (a Lisp-based Postgres client) that doesn't support SNI. Neon requires SNI for endpoint routing — connections from pgloader simply fail. We wrote `seed-postgres.php` as a drop-in replacement using PHP's PDO.

## Architecture

```
Docker Build (Depot, US)
├── Stage 1: Install Maho to SQLite (/maho-seed.db)
│   ├── Clone feature branch from mageaustralia/maho
│   ├── composer install --no-dev
│   ├── maho install (SQLite) + sample data
│   └── Extract crypt key, apply Neon patches
└── Stage 2: Runtime image
    ├── pdo_pgsql + postgresql-client
    ├── FrankenPHP (Caddy + PHP 8.4 ZTS)
    └── seed-postgres.php + startup script

Fly.io Boot (Sydney)
├── Check Neon table count
├── If empty → run seed-postgres.php (SQLite → Postgres, ~13s)
├── Reset sequences to MAX(id)+1
├── Generate local.xml for Postgres
├── Reindex if fresh seed (~22s)
└── Launch FrankenPHP
```

## Quick Start

```bash
# Prerequisites: fly CLI, a Neon project

# 1. Create Fly app
fly apps create my-maho-app --org your-org

# 2. Set Neon password as a secret
fly secrets set PG_PASS=your-neon-password -a my-maho-app

# 3. Deploy
fly deploy -a my-maho-app --remote-only
```

First deploy takes ~36 seconds (migration + reindex). Subsequent deploys take ~1 second (DB already populated).

## The Migration Script (`seed-postgres.php`)

A standalone PHP script that migrates a Maho SQLite database to PostgreSQL using PDO.

### What it migrates

| Data | Method |
|------|--------|
| Tables | Column types mapped (SQLite → Postgres), NOT NULL, defaults preserved |
| Primary keys | Single integer PKs become `BIGSERIAL` (auto-increment) |
| Data | Batch INSERT (500 rows), row-by-row fallback on errors |
| Indexes | Replayed from `sqlite_master` (syntax is cross-compatible) |
| Foreign keys | Read via `PRAGMA foreign_key_list`, created as ALTER TABLE constraints |
| Sequences | Reset to `MAX(id)` via `pg_get_serial_sequence()` catalog lookup |

### Type mapping

| SQLite | PostgreSQL |
|--------|------------|
| `TINYINT`/`INT`/`BIGINT`/`INTEGER` | `BIGINT` (or `BIGSERIAL` for PKs) |
| `VARCHAR(N)` | `VARCHAR(N)` |
| `TEXT`/`MEDIUMTEXT`/`LONGTEXT` | `TEXT` |
| `DECIMAL(P,S)` | `DECIMAL(P,S)` |
| `DATETIME`/`TIMESTAMP` | `TIMESTAMP` |
| `FLOAT`/`DOUBLE`/`REAL` | `DOUBLE PRECISION` |

### Sequence fix

SQLite has no sequences — it uses `ROWID` for auto-increment. When data is migrated to Postgres, `BIGSERIAL` columns create sequences that default to 1, even though the table already has rows. Without a fix, the next INSERT causes a duplicate key error.

`seed-postgres.php` queries `pg_get_serial_sequence()` from the Postgres catalog to find the actual sequence name for each serial column, then calls `setval()` to sync it to `MAX(id)`. This is more robust than guessing the sequence name (`{table}_{col}_seq`), which can be wrong if Postgres truncated a long identifier.

### Usage

```bash
# Standalone (requires PG_HOST, PG_USER, PG_PASS, PG_DBNAME env vars)
php seed-postgres.php /path/to/maho-seed.db

# As part of Fly.io startup (automatic)
# start-pgloader.sh calls it when Neon has < 100 tables
```

## Neon Gotchas

- **`session_replication_role` is restricted** — Neon's `neon_superuser` role is not a real superuser. Maho's FK disable/enable during reindex uses `session_replication_role = replica`, which fails. `neon-patch.php` patches affected core files to use `ALTER TABLE ... DISABLE TRIGGER ALL` instead.
- **SNI required** — Old Postgres clients (pgloader, old libpq) can't connect. PHP PDO works fine.
- **No `--force` install** — Maho's `--force` flag deletes `local.xml` then tries to boot the app, which crashes. Don't use it.

## Files

| File | Purpose |
|------|---------|
| `Dockerfile` | Two-stage build: SQLite install → runtime with pdo_pgsql |
| `start-pgloader.sh` | Startup: seed check → migration → config → reindex → FrankenPHP |
| `seed-postgres.php` | SQLite → Postgres migration script |
| `neon-patch.php` | Patches Maho core for Neon compatibility |
| `fly.toml` | Fly.io config (region, VM, health checks, env vars) |
| `Caddyfile` | FrankenPHP/Caddy config |

## Performance

| Metric | Value |
|--------|-------|
| Cold start (empty DB → serving) | ~36s |
| Migration (358 tables, 37k rows) | ~13s |
| Reindex | ~22s |
| Warm start (DB populated) | ~1s |
| TTFB (Neon, Sydney→Sydney) | ~0.46s |

## Admin

- **URL**: `https://<app>.fly.dev/admin`
- **SSH**: `fly ssh console -a my-maho-app`
- **Logs**: `fly logs -a my-maho-app`
- **Force re-seed**: Drop all Neon tables, then redeploy
