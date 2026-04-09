#!/bin/sh
set -e

# --- Media persistence ---
mkdir -p /data/media
if [ -d /app/public/media ] && [ ! -L /app/public/media ]; then
    cp -rn /app/public/media/* /data/media/ 2>/dev/null || true
    rm -rf /app/public/media
fi
ln -sfn /data/media /app/public/media

# --- Symfony cache ---
mkdir -p /data/symfony-cache
export APP_CACHE_DIR=/data/symfony-cache

# --- Litestream mode (if BUCKET_NAME set) ---
if [ -n "$BUCKET_NAME" ]; then
    if [ ! -f /data/maho.sqlite ]; then
        echo "No local database found. Attempting restore from Tigris..."
        litestream restore -if-replica-exists -o /data/maho.sqlite /data/maho.sqlite || true
    fi
    echo "Starting with Litestream replication..."
    exec litestream replicate -exec "frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile"
else
    echo "Starting without replication..."
    exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
fi
