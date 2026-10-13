# syntax=docker/dockerfile:1
FROM composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer
FROM php:8.3-fpm-bookworm@sha256:f768bd9142444f6126a690697b028c23f30878c3b628a7c355dfce75e29cc045 AS php-base
RUN apt-get update && apt-get install -y --no-install-recommends libgmp-dev libonig-dev libzip-dev nginx-light tini libfcgi-bin curl ca-certificates \
    && docker-php-ext-install -j2 bcmath gmp pcntl zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
FROM php-base AS dependencies
WORKDIR /build
COPY composer.json composer.lock ./
COPY scripts/patch-madeline.php ./scripts/patch-madeline.php
RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts --no-plugins \
    && composer check-platform-reqs --no-dev && php scripts/patch-madeline.php
FROM dependencies AS source
COPY . /build
FROM php-base AS runtime
ARG VCS_REF=unknown
LABEL org.opencontainers.image.source="https://github.com/roboapi2/self-bot-telegram" \
      org.opencontainers.image.revision="$VCS_REF"
RUN groupadd -g 10001 ubcc && useradd -u 10001 -g ubcc -s /usr/sbin/nologin -d /opt/app ubcc
WORKDIR /opt/app
COPY --from=source /build/index.php /build/api.php /build/lib.php /build/runtime.php /build/worker.php /build/supervisor.php /build/console.php /build/bot_template.php /build/qr_worker.php /build/madeline_tools.php ./
COPY --from=source /build/madeline_bootstrap.php ./
COPY --from=dependencies /build/vendor ./vendor
COPY --from=source /build/docker/entrypoint.sh /build/docker/health.php ./docker/
COPY --from=source /build/assets ./public/assets
COPY --from=source /build/scripts/migrate-storage.php ./scripts/migrate-storage.php
COPY docker/config.php ./config.php
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-ubcc.ini
COPY docker/nginx.conf /etc/nginx/nginx.conf
RUN mkdir -p accounts .panel && chown ubcc:ubcc accounts .panel \
    && chmod 0700 accounts .panel && chmod 0555 docker/entrypoint.sh
USER 10001:10001
EXPOSE 8080 9000
STOPSIGNAL SIGTERM
ENTRYPOINT ["/usr/bin/tini", "-s", "-g", "--", "/opt/app/docker/entrypoint.sh"]
CMD ["web"]
FROM runtime AS ci
USER root
RUN apt-get update && apt-get install -y --no-install-recommends python3 ripgrep git && rm -rf /var/lib/apt/lists/*
COPY --from=source /build /opt/ci
WORKDIR /opt/ci
ENTRYPOINT []
CMD ["bash", "scripts/test.sh"]
FROM runtime AS production
