FROM mahocommerce/maho:26.3.0-php8.4

# Install Litestream for SQLite streaming backups to Tigris
ADD https://github.com/benbjohnson/litestream/releases/download/v0.3.13/litestream-v0.3.13-linux-amd64.tar.gz /tmp/litestream.tar.gz
RUN tar -xzf /tmp/litestream.tar.gz -C /usr/local/bin/ litestream && rm /tmp/litestream.tar.gz

# Replace stock Maho with API Platform branch
WORKDIR /tmp
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN rm -rf /app && \
    git clone --branch feature/api-platform --single-branch --depth 1 \
        https://github.com/mageaustralia/maho.git /app && \
    cd /app && \
    composer install --no-dev --optimize-autoloader --no-interaction && \
    rm -rf /app/.git
WORKDIR /app

# API Platform expects this directory
RUN mkdir -p /app/app/code/core/Maho/ApiPlatform/symfony/src/ApiResource

# Caddyfile with /api/* → rest.php routing
COPY Caddyfile /etc/caddy/Caddyfile
COPY Caddyfile /etc/frankenphp/Caddyfile

# Litestream config + startup script
COPY litestream.yml /etc/litestream.yml
COPY start.sh /start.sh
RUN chmod +x /start.sh

ENTRYPOINT []
CMD ["/start.sh"]
