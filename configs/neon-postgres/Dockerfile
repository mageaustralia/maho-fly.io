FROM mahocommerce/maho:26.3.0-php8.4

# Install pdo_pgsql extension for Neon Postgres
# FrankenPHP image is Debian-based with docker-php-ext-install available
RUN apt-get update && \
    apt-get install -y --no-install-recommends libpq-dev && \
    docker-php-ext-install pdo_pgsql && \
    rm -rf /var/lib/apt/lists/*

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

# Remove local.xml so installer can create it on first boot
RUN rm -f /app/app/etc/local.xml

# Startup script
COPY start.sh /start.sh
RUN chmod +x /start.sh

ENTRYPOINT []
CMD ["/start.sh"]
