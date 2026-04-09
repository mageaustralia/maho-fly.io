#!/bin/sh

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

# --- Ensure no stale local.xml ---
rm -f /app/app/etc/local.xml

# --- Patch Pgsql adapter for Neon (no superuser = no session_replication_role) ---
PGSQL_ADAPTER="/app/lib/Maho/Db/Adapter/Pdo/Pgsql.php"
if grep -q "SET session_replication_role = replica" "$PGSQL_ADAPTER" 2>/dev/null; then
    sed -i "s|\$this->raw_query('SET session_replication_role = replica');|try { \$this->raw_query('SET session_replication_role = replica'); } catch (\\\\Throwable \$e) { /* Neon: not superuser */ }|" "$PGSQL_ADAPTER"
    sed -i "s|\$this->raw_query('SET session_replication_role = DEFAULT');|try { \$this->raw_query('SET session_replication_role = DEFAULT'); } catch (\\\\Throwable \$e) { /* Neon: not superuser */ }|" "$PGSQL_ADAPTER"
    echo "Patched Pgsql adapter for Neon compatibility."
fi

# Also patch Install.php and SampleData.php
INSTALL_PHP="/app/lib/MahoCLI/Commands/Install.php"
if grep -q "SET session_replication_role = replica" "$INSTALL_PHP" 2>/dev/null; then
    sed -i "s|\\\$pdo->exec('SET session_replication_role = replica');|try { \$pdo->exec('SET session_replication_role = replica'); } catch (\\\\Throwable \$e) {}|" "$INSTALL_PHP"
    sed -i "s|\\\$pdo->exec('SET session_replication_role = DEFAULT');|try { \$pdo->exec('SET session_replication_role = DEFAULT'); } catch (\\\\Throwable \$e) {}|" "$INSTALL_PHP"
    echo "Patched Install.php for Neon compatibility."
fi

SAMPLEDATA_PHP="/app/app/code/core/Mage/Install/Model/Installer/SampleData.php"
if grep -q "SET session_replication_role = replica" "$SAMPLEDATA_PHP" 2>/dev/null; then
    sed -i "s|\\\$pdo->exec('SET session_replication_role = replica');|try { \$pdo->exec('SET session_replication_role = replica'); } catch (\\\\Throwable \$e) {}|" "$SAMPLEDATA_PHP"
    sed -i "s|\\\$pdo->exec('SET session_replication_role = DEFAULT');|try { \$pdo->exec('SET session_replication_role = DEFAULT'); } catch (\\\\Throwable \$e) {}|" "$SAMPLEDATA_PHP"
    echo "Patched SampleData.php for Neon compatibility."
fi

# --- Install with Neon Postgres ---
if [ ! -f /data/.installed ]; then
    echo "First boot — clearing Neon DB and installing Maho..."
    php -r "
        \$pdo = new PDO('pgsql:host=' . getenv('NEON_HOST') . ';dbname=' . getenv('NEON_DBNAME') . ';sslmode=require',
                        getenv('NEON_USER'), getenv('NEON_PASS'));
        \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        \$tables = \$pdo->query(\"SELECT tablename FROM pg_tables WHERE schemaname = 'public'\")->fetchAll(PDO::FETCH_COLUMN);
        foreach (\$tables as \$t) { \$pdo->exec(\"DROP TABLE IF EXISTS \\\"\$t\\\" CASCADE\"); }
        echo 'Cleared ' . count(\$tables) . ' tables from Neon DB.' . PHP_EOL;
    "

    cd /app
    php maho install \
        --license_agreement_accepted=yes \
        --locale=en_AU \
        --timezone=Australia/Sydney \
        --default_currency=AUD \
        --db_host="$NEON_HOST" \
        --db_name="$NEON_DBNAME" \
        --db_user="$NEON_USER" \
        --db_pass="$NEON_PASS" \
        --db_engine=pgsql \
        --session_save=files \
        --url=https://my-maho-app.fly.dev/ \
        --use_secure=yes \
        --secure_base_url=https://my-maho-app.fly.dev/ \
        --use_secure_admin=yes \
        --admin_frontname=admin \
        --admin_lastname=Admin \
        --admin_firstname=Maho \
        --admin_email=admin@example.com \
        --admin_username=admin \
        --admin_password=ChangeMe2026Admin! \
        --sample_data=yes \
        -vvv 2>&1
    INSTALL_EXIT=$?
    echo "Install exit code: $INSTALL_EXIT"
    if [ "$INSTALL_EXIT" -ne 0 ]; then
        echo "Install failed. Checking state..."
        echo "local.xml exists: $(test -f /app/app/etc/local.xml && echo YES || echo NO)"
        echo "Sleeping for SSH debug..."
        exec sleep 3600
    fi
    touch /data/.installed
    echo "Install complete."
fi

echo "Starting with Neon Postgres (Sydney)..."
exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
