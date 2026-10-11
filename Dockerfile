# Amaya Log Intelligence — container image
# nginx + PHP-FPM + MariaDB on Alpine. Suitable for Render (Docker runtime)
# and any host that can run an OCI container.
FROM php:8.3-fpm-alpine

# nginx + process supervision + MariaDB.
# The official php:8.3-fpm-alpine image already bundles pdo, mbstring,
# openssl, session and json. Do NOT apk-install php83-* packages: that would
# pull a second, conflicting PHP build from Alpine's repository.
RUN apk add --no-cache \
        nginx \
        supervisor \
        mariadb \
        mariadb-client \
    && rm -rf /var/cache/apk/*

# pdo_mysql availability depends on the image variant (mysqlnd is present, but
# the driver may not be compiled in). Install it only when missing, so the
# build is deterministic across php:8.3-fpm-alpine revisions.
RUN if ! php -m | grep -q '^pdo_mysql$'; then \
        docker-php-ext-install -j"$(nproc)" pdo_mysql; \
    fi \
    && php -m | grep -q '^pdo_mysql$' \
    && php -m | grep -q '^session$'

# Application code lives under /var/www/app; the PHP entrypoints (index.php,
# api.php, login.php…) are served from its public/ subdirectory.
WORKDIR /var/www/app
COPY . /var/www/app
RUN mkdir -p /var/lib/mysql /run/mysqld /var/www/app/storage \
    && chown -R www-data:www-data /var/www/app/storage \
    && chmod +x /var/www/app/bin/docker-entrypoint.sh

# nginx config (document root matches SCRIPT_FILENAME in nginx.conf)
COPY deploy/nginx.conf /etc/nginx/http.d/app.conf

# Supervisord keeps nginx, php-fpm and mariadb alive together.
COPY deploy/supervisord.conf /etc/supervisord.conf

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1/health.php") ? 0 : 1);'

ENTRYPOINT ["/var/www/app/bin/docker-entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]