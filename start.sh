#!/bin/sh
set -e

echo "=== Maho Fly.io Startup (pgloader edition) ==="
START_TIME=$(date +%s)

# --- HTTPS detection behind Fly proxy ---
cat > /tmp/prepend.php << 'PHPEOF'
<?php
if (isset($_SERVER["HTTP_X_FORWARDED_PROTO"]) && $_SERVER["HTTP_X_FORWARDED_PROTO"] === "https") {
    $_SERVER["HTTPS"] = "on";
    $_SERVER["SERVER_PORT"] = 443;
}
PHPEOF
echo "auto_prepend_file=/tmp/prepend.php" > /usr/local/etc/php/conf.d/99-prepend.ini

# --- Symfony cache (ephemeral per instance) ---
mkdir -p /tmp/symfony-cache
export APP_CACHE_DIR=/tmp/symfony-cache

# --- Ensure no stale local.xml ---
rm -f /app/app/etc/local.xml

# --- DB config from env vars ---
# Neon connection string: postgres://user:pass@host/dbname?sslmode=require
PG_HOST="${PG_HOST:?Set PG_HOST}"
PG_USER="${PG_USER:?Set PG_USER}"
PG_PASS="${PG_PASS:?Set PG_PASS as a Fly secret}"
PG_DBNAME="${PG_DBNAME:-neondb}"
PG_SSLMODE="${PG_SSLMODE:-require}"

PGSQL_URI="postgresql://${PG_USER}:${PG_PASS}@${PG_HOST}/${PG_DBNAME}?sslmode=${PG_SSLMODE}"

# --- Check if DB needs seeding ---
TABLE_COUNT=$(PGPASSWORD="$PG_PASS" psql \
    "host=$PG_HOST dbname=$PG_DBNAME user=$PG_USER sslmode=$PG_SSLMODE" \
    -t -c "SELECT count(*) FROM pg_tables WHERE schemaname = 'public';" 2>/dev/null | tr -d ' ')

if [ -z "$TABLE_COUNT" ] || [ "$TABLE_COUNT" -lt 100 ]; then
    echo "=== Neon has $TABLE_COUNT tables — seeding from SQLite via pgloader ==="
    SEED_START=$(date +%s)

    # Drop existing tables if any (clean slate)
    if [ -n "$TABLE_COUNT" ] && [ "$TABLE_COUNT" -gt 0 ]; then
        echo "Dropping $TABLE_COUNT existing tables..."
        PGPASSWORD="$PG_PASS" psql \
            "host=$PG_HOST dbname=$PG_DBNAME user=$PG_USER sslmode=$PG_SSLMODE" \
            -c "DO \$\$ DECLARE r RECORD; BEGIN FOR r IN (SELECT tablename FROM pg_tables WHERE schemaname = 'public') LOOP EXECUTE 'DROP TABLE IF EXISTS \"' || r.tablename || '\" CASCADE'; END LOOP; END \$\$;" \
            2>&1
    fi

    # Generate pgloader command file with actual connection string
    sed "s|{{PGSQL_URI}}|${PGSQL_URI}|" /pgloader-seed.load > /tmp/pgloader-run.load

    echo "Running pgloader (SQLite → Neon)..."
    pgloader /tmp/pgloader-run.load 2>&1 | tail -30

    SEED_END=$(date +%s)
    SEED_ELAPSED=$((SEED_END - SEED_START))

    # Verify
    TABLE_COUNT=$(PGPASSWORD="$PG_PASS" psql \
        "host=$PG_HOST dbname=$PG_DBNAME user=$PG_USER sslmode=$PG_SSLMODE" \
        -t -c "SELECT count(*) FROM pg_tables WHERE schemaname = 'public';" 2>/dev/null | tr -d ' ')
    echo "=== pgloader complete: $TABLE_COUNT tables in ${SEED_ELAPSED}s ==="

    if [ "$TABLE_COUNT" -lt 100 ]; then
        echo "ERROR: Seeding failed — only $TABLE_COUNT tables. Sleeping for debug..."
        exec sleep 3600
    fi
else
    echo "=== Neon has $TABLE_COUNT tables — skipping seed ==="
fi

# --- Generate local.xml for Neon ---
CRYPT_KEY=$(cat /maho-crypt-key)
cat > /app/app/etc/local.xml << XMLEOF
<?xml version="1.0"?>
<config>
  <global>
    <install><date>$(date)</date></install>
    <crypt><key>${CRYPT_KEY}</key></crypt>
    <disable_local_modules>false</disable_local_modules>
    <resources>
      <db><table_prefix></table_prefix></db>
      <default_setup>
        <connection>
          <host>${PG_HOST}</host>
          <username>${PG_USER}</username>
          <password>${PG_PASS}</password>
          <dbname>${PG_DBNAME}</dbname>
          <engine>pgsql</engine>
          <model>pgsql</model>
          <type>pdo_pgsql</type>
          <sslmode>${PG_SSLMODE}</sslmode>
          <active>1</active>
        </connection>
      </default_setup>
    </resources>
    <session_save>files</session_save>
  </global>
  <admin><routers><adminhtml><args><frontName>admin</frontName></args></adminhtml></routers></admin>
</config>
XMLEOF
echo "local.xml generated for Neon (${PG_HOST})."

# --- Fix default config.xml MySQL-isms for Postgres ---
sed -i 's|<initStatements>SET NAMES utf8</initStatements>|<initStatements></initStatements>|' /app/app/etc/config.xml

END_TIME=$(date +%s)
TOTAL_ELAPSED=$((END_TIME - START_TIME))
echo "=== Startup complete in ${TOTAL_ELAPSED}s — launching FrankenPHP ==="

exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
