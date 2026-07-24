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
    test -f /opt/moodle/public/version.php; \
    test -f /opt/moodle/public/index.php

FROM ${MOODLE_PHP_IMAGE}

LABEL org.opencontainers.image.title="Moodle for Coolify" \
      org.opencontainers.image.description="Moodle image based on moodlehq/moodle-php-apache"

RUN set -eux; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    mkdir -p /opt/moodle /usr/local/share

COPY --chown=root:root --from=moodle-download /opt/moodle/ /opt/moodle/

# Add plugins/themes by mirroring their final Moodle paths below moodle-overlay/.
# Examples: moodle-overlay/public/mod/example, moodle-overlay/public/theme/example.
COPY --chown=root:root moodle-overlay/ /opt/moodle/

COPY --chmod=0644 docker/php-moodle.ini "$PHP_INI_DIR/conf.d/zz-moodle.ini"
COPY --chown=root:www-data --chmod=0440 docker/config.php /opt/moodle/config.php
COPY --chmod=0644 docker/moodle-db-state.php /usr/local/libexec/moodle-db-state.php
COPY --chmod=0755 docker/moodle-code-prepare /usr/local/bin/moodle-code-prepare
COPY --chmod=0755 docker/moodle-prepare /usr/local/bin/moodle-prepare
COPY --chmod=0755 docker/moodle-cron /usr/local/bin/moodle-cron

RUN set -eux; \
    find /opt/moodle \( -type f -o -type l \) -printf 'F\t%P\n' \
      > /usr/local/share/moodle-image-manifest; \
    find /opt/moodle -depth -mindepth 1 -type d -printf 'D\t%P\n' \
      >> /usr/local/share/moodle-image-manifest; \
    tar --sort=name --mtime='UTC 1970-01-01' --owner=0 --group=0 --numeric-owner \
      -cf - -C /opt/moodle . \
      | sha256sum \
      | cut -d ' ' -f 1 \
      > /usr/local/share/moodle-image-fingerprint; \
    chmod 0444 \
      /usr/local/share/moodle-image-manifest \
      /usr/local/share/moodle-image-fingerprint

WORKDIR /var/www/html
