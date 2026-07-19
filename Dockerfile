# syntax=docker/dockerfile:1

FROM php:8.5-fpm-alpine

# Upstream Spotweb commit to build. CI overrides this with the value in
# .upstream-ref, so images are reproducible and only change when upstream does.
ARG SPOTWEB_REF=9cb4d90482ab026ba59ee6439de274a7f4f616e9

ENV SPOTWEB_HOME=/var/www/spotweb \
    TZ=Etc/UTC

WORKDIR /var/www/spotweb

RUN set -eux; \
    apk add --no-cache \
        nginx \
        supervisor \
        su-exec \
        tzdata \
        libintl \
        libpng \
        libjpeg-turbo \
        freetype \
        libzip \
        libpq \
        libxml2; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        git \
        oniguruma-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libzip-dev \
        gettext-dev \
        postgresql-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        gd \
        mbstring \
        pdo_mysql \
        pdo_pgsql \
        zip \
        gettext \
        bcmath; \
    # Fetch Spotweb at the exact pinned commit. vendor/ is committed upstream,
    # so no composer install is required.
    git init -q "$SPOTWEB_HOME"; \
    git -C "$SPOTWEB_HOME" remote add origin https://github.com/spotweb/spotweb.git; \
    git -C "$SPOTWEB_HOME" fetch -q --depth 1 origin "$SPOTWEB_REF"; \
    git -C "$SPOTWEB_HOME" checkout -q FETCH_HEAD; \
    rm -rf "$SPOTWEB_HOME/.git"; \
    install -d -o www-data -g www-data \
        /config \
        /var/lib/nginx /var/lib/nginx/tmp /var/lib/nginx/logs \
        /var/log/nginx /run/nginx; \
    chown -R www-data:www-data "$SPOTWEB_HOME"; \
    apk del .build-deps; \
    rm -rf /var/cache/apk/* /tmp/* /var/tmp/*

COPY rootfs/ /
RUN chmod +x /usr/local/bin/entrypoint.sh

# nginx serves the UI on 8080; php-fpm listens internally on 127.0.0.1:9000.
EXPOSE 8080

# Served by nginx directly, so the container reports healthy as soon as the web
# stack is up, independent of database state. Coolify honours this healthcheck.
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/healthz || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
