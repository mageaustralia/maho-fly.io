FROM mahocommerce/maho:26.3.0-php8.4

# Install Litestream for SQLite streaming backups to Tigris (fallback mode)
ADD https://github.com/benbjohnson/litestream/releases/download/v0.3.13/litestream-v0.3.13-linux-amd64.tar.gz /tmp/litestream.tar.gz
RUN tar -xzf /tmp/litestream.tar.gz -C /usr/local/bin/ litestream && rm /tmp/litestream.tar.gz

# Install libSQL PHP extension for Turso embedded replicas
# FrankenPHP uses ZTS (thread-safe) PHP
ADD https://github.com/tursodatabase/turso-client-php/releases/download/turso-php-extension-v1.6.2/libsql_php-turso-php-extension-v1.6.2-php-8.4-ts-x86_64-unknown-linux-gnu.tar.gz /tmp/libsql.tar.gz
RUN mkdir -p /tmp/libsql && \
    tar -xzf /tmp/libsql.tar.gz -C /tmp/libsql --strip-components=1 && \
    cp /tmp/libsql/liblibsql_php.so $(php -r "echo ini_get('extension_dir');")/libsql_php.so && \
    echo "extension=libsql_php.so" > /usr/local/etc/php/conf.d/90-libsql.ini && \
    rm -rf /tmp/libsql /tmp/libsql.tar.gz

# Replace stock Maho with API Platform branch
WORKDIR /tmp
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN rm -rf /app && \
    git clone --branch feature/api-platform --single-branch --depth 1 \
        https://github.com/mageaustralia/maho.git /app && \
    cd /app && \
    composer require tursodatabase/turso-doctrine-dbal --no-interaction && \
    composer install --no-dev --optimize-autoloader --no-interaction && \
    rm -rf /app/.git
WORKDIR /app

# API Platform expects this directory
RUN mkdir -p /app/app/code/core/Maho/ApiPlatform/symfony/src/ApiResource

# Turso-aware SQLite adapter override
COPY TursoSqlite.php /app/lib/Maho/Db/Adapter/Pdo/TursoSqlite.php
RUN mkdir -p /app/app/code/community/MageAustralia/Turso/etc
COPY turso-override.xml /app/app/code/community/MageAustralia/Turso/etc/config.xml

# Caddyfile with /api/* → rest.php routing
COPY Caddyfile /etc/caddy/Caddyfile
COPY Caddyfile /etc/frankenphp/Caddyfile

# Litestream config + startup script
COPY litestream.yml /etc/litestream.yml
COPY start.sh /start.sh
RUN chmod +x /start.sh

ENTRYPOINT []
CMD ["/start.sh"]
