#!/bin/sh
set -e

# --- Media persistence ---
# Move media to persistent volume so it survives deploys
mkdir -p /data/media
if [ -d /app/public/media ] && [ ! -L /app/public/media ]; then
    # First deploy: move existing media to volume, then symlink
    cp -rn /app/public/media/* /data/media/ 2>/dev/null || true
    rm -rf /app/public/media
fi
ln -sfn /data/media /app/public/media

# --- Symfony cache (writable dir on persistent volume) ---
mkdir -p /data/symfony-cache
export APP_CACHE_DIR=/data/symfony-cache

# --- Database mode selection ---
# Priority: Turso embedded replica > Litestream + local SQLite > plain SQLite

if [ -n "$TURSO_SYNC_URL" ] && [ -n "$TURSO_AUTH_TOKEN" ]; then
    # === TURSO MODE ===
    # Turso embedded replica: local SQLite file syncs to/from Turso
    # The adapter handles sync automatically via libSQL extension
    echo "Starting with Turso embedded replica..."
    echo "  Sync URL: $TURSO_SYNC_URL"
    echo "  Local replica: /data/maho.sqlite"
    echo "  Sync interval: ${TURSO_SYNC_INTERVAL:-5}s"

    # If no local DB exists, Turso will pull from remote on first connect
    exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile

elif [ -n "$BUCKET_NAME" ]; then
    # === LITESTREAM MODE ===
    # SQLite restore from Tigris (if DB missing but backup exists)
    if [ ! -f /data/maho.sqlite ]; then
        echo "No local database found. Attempting restore from Tigris..."
        litestream restore -if-replica-exists -o /data/maho.sqlite /data/maho.sqlite || true
    fi

    echo "Starting with Litestream replication..."
    exec litestream replicate -exec "frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile"

else
    # === PLAIN SQLITE MODE ===
    echo "No TURSO_SYNC_URL or BUCKET_NAME set, starting without replication..."
    exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
fi
