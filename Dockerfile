# syntax=docker/dockerfile:1.7

ARG MOODLE_PHP_IMAGE=moodlehq/moodle-php-apache:8.3-bookworm

FROM alpine:3.22 AS moodle-download
ARG MOODLE_VERSION=5.2.1
ARG MOODLE_SERIES=502
ARG MOODLE_SHA256=db1167f3deef899aef6d4b273d3bc901b102c5d9de2e97289492505c2ec29629

RUN set -eux; \
    apk add --no-cache ca-certificates curl tar; \
    test -n "$MOODLE_VERSION"; \
    test -n "$MOODLE_SERIES"; \
    test -n "$MOODLE_SHA256"; \
    curl --fail --location --retry 5 --retry-all-errors \
      "https://download.moodle.org/download.php/direct/stable${MOODLE_SERIES}/moodle-${MOODLE_VERSION}.tgz" \
      --output /tmp/moodle.tgz; \
    echo "${MOODLE_SHA256}  /tmp/moodle.tgz" | sha256sum -c -; \
    mkdir -p /opt/moodle; \
    tar -xzf /tmp/moodle.tgz --strip-components=1 -C /opt/moodle; \
    test -f /opt/moodle/version.php; \
    test -d /opt/moodle/public

FROM ${MOODLE_PHP_IMAGE}

LABEL org.opencontainers.image.title="Moodle for Coolify" \
      org.opencontainers.image.description="Immutable Moodle image based on moodlehq/moodle-php-apache"

RUN set -eux; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    find /var/www/html -mindepth 1 -maxdepth 1 -exec rm -rf {} +

COPY --from=moodle-download /opt/moodle/ /var/www/html/

# Add plugins/themes by mirroring their final Moodle paths below moodle-overlay/.
# Examples: moodle-overlay/mod/example, moodle-overlay/theme/example.
COPY moodle-overlay/ /var/www/html/

COPY docker/php-moodle.ini "$PHP_INI_DIR/conf.d/zz-moodle.ini"
COPY docker/config.php /var/www/html/config.php
COPY docker/moodle-db-state.php /usr/local/libexec/moodle-db-state.php
COPY docker/moodle-prepare /usr/local/bin/moodle-prepare
COPY docker/moodle-cron /usr/local/bin/moodle-cron

RUN set -eux; \
    rm -f /var/www/html/.gitkeep; \
    find /var/www/html -type d -exec chmod 0755 {} +; \
    find /var/www/html -type f -exec chmod 0644 {} +; \
    chown root:www-data /var/www/html/config.php; \
    chmod 0440 /var/www/html/config.php; \
    chmod 0755 /usr/local/bin/moodle-prepare /usr/local/bin/moodle-cron; \
    chmod 0644 /usr/local/libexec/moodle-db-state.php

WORKDIR /var/www/html
