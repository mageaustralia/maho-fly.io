#!/usr/bin/env bash
#
# Deploy and setup Maho on Fly.io with SQLite
#
# Usage:
#   ./setup.sh [APP_NAME] [--sample-data] [--url URL] [--password PASSWORD]
#
# Examples:
#   ./setup.sh                                    # Default: my-maho-app, no sample data
#   ./setup.sh mystore --sample-data              # With sample data
#   ./setup.sh mystore --url https://shop.example.com/ --password MyPass14chars!
#

set -euo pipefail

# --- Parse arguments ---
APP_NAME="${1:-my-maho-app}"
shift 2>/dev/null || true

SAMPLE_DATA=false
ADMIN_PASSWORD="ChangeMe14chars!"
BASE_URL=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --sample-data) SAMPLE_DATA=true; shift ;;
        --password) ADMIN_PASSWORD="$2"; shift 2 ;;
        --url) BASE_URL="$2"; shift 2 ;;
        *) echo "Unknown option: $1"; exit 1 ;;
    esac
done

if [[ -z "$BASE_URL" ]]; then
    BASE_URL="https://${APP_NAME}.fly.dev/"
fi

SSH="flyctl ssh console -a $APP_NAME -C"

echo "==> Setting up Maho on Fly app: $APP_NAME"
echo "    URL: $BASE_URL"
echo "    Sample data: $SAMPLE_DATA"
echo ""

# --- Step 1: Upload HTTPS proxy files to persistent volume ---
echo "==> Uploading proxy config to /data/..."
cat setup/prepend.php | $SSH "sh -c 'cat > /data/prepend.php'"
cat setup/99-proxy.ini | $SSH "sh -c 'cat > /data/99-proxy.ini'"
echo "    Done"

# --- Step 2: Write local.xml ---
echo "==> Writing local.xml..."
CRYPT_KEY=$(openssl rand -hex 16)
cat <<XMLEOF | $SSH "sh -c 'mkdir -p /app/app/etc && cat > /app/app/etc/local.xml'"
<?xml version="1.0"?>
<config>
  <global>
    <install><date>$(date -u '+%a, %d %b %Y %H:%M:%S %z')</date></install>
    <crypt><key>${CRYPT_KEY}</key></crypt>
    <resources>
      <db><table_prefix></table_prefix></db>
      <default_setup>
        <connection>
          <host></host>
          <username></username>
          <password></password>
          <dbname>/data/maho.sqlite</dbname>
          <engine>sqlite</engine>
          <initStatements></initStatements>
          <active>1</active>
        </connection>
      </default_setup>
    </resources>
    <session_save>files</session_save>
  </global>
  <admin>
    <routers>
      <adminhtml>
        <args>
          <frontName>admin</frontName>
        </args>
      </adminhtml>
    </routers>
  </admin>
</config>
XMLEOF
echo "    Done (key: ${CRYPT_KEY})"

# --- Step 3: Upload and run installer ---
echo "==> Uploading installer script..."
cat setup/install.php | $SSH "sh -c 'cat > /tmp/install.php'"

echo "==> Running Maho install..."
$SSH "php /tmp/install.php \
    --license_agreement_accepted=yes \
    --locale=en_AU \
    --timezone=Australia/Sydney \
    --default_currency=AUD \
    --db_name=/data/maho.sqlite \
    --db_engine=sqlite \
    --db_host= \
    --db_user= \
    --db_pass= \
    --url=${BASE_URL} \
    --admin_frontname=admin \
    --admin_lastname=User \
    --admin_firstname=Admin \
    --admin_email=admin@example.com \
    --admin_username=admin \
    --admin_password=${ADMIN_PASSWORD}"

# --- Step 4: Sample data (optional) ---
if [[ "$SAMPLE_DATA" == "true" ]]; then
    echo ""
    echo "==> Uploading sample data script..."
    cat setup/sample-data.php | $SSH "sh -c 'cat > /tmp/sample_data.php'"

    echo "==> Importing sample data (this may take a minute)..."
    $SSH "php /tmp/sample_data.php"

    echo "==> Reindexing..."
    $SSH "php /app/maho index:reindex:all"
fi

# --- Step 5: Flush cache ---
echo ""
echo "==> Flushing cache..."
$SSH "php /app/maho cache:flush"

echo ""
echo "==> Setup complete!"
echo ""
echo "    Store URL: ${BASE_URL}"
echo "    Admin URL: ${BASE_URL}admin/"
echo "    Username:  admin"
echo "    Password:  ${ADMIN_PASSWORD}"
echo ""
