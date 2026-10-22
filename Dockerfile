FROM golang:1.26.10-alpine@sha256:5cb67f19c5e7994dcb80c074f7cfca8aeeada45bbdca035f73df269cefdcce61 AS roadrunner-builder

ADD --checksum=sha256:32e0196ae551b6ad2abf50cbfc961f91e9dd4134bd177b0f7aa03dada9e41979 \
    https://github.com/roadrunner-server/roadrunner/archive/refs/tags/v2025.1.15.tar.gz \
    /tmp/roadrunner.tar.gz
RUN mkdir -p /src /out && tar -xzf /tmp/roadrunner.tar.gz --strip-components=1 -C /src
WORKDIR /src
RUN go get github.com/rabbitmq/amqp091-go@v1.13.0 \
        github.com/klauspost/compress@v1.18.7 \
        golang.org/x/crypto@v0.57.0 \
        golang.org/x/net@v0.60.0 \
        golang.org/x/text@v0.42.0 \
        google.golang.org/grpc@v1.83.2 && \
    go mod tidy && \
    CGO_ENABLED=0 go build -trimpath -ldflags '-s -w -buildid=' -o /out/rr cmd/rr/main.go

FROM scratch AS roadrunner-artifact
COPY --from=roadrunner-builder /out/rr /rr

FROM composer:2@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040 AS composer

FROM php:8.5.12-cli-alpine3.23@sha256:307c3a4ece9b0dc24d4e5c1ad6229aab15354144b12f3afed209ed847c855697 AS base

ARG SHLINK_VERSION=latest
ENV SHLINK_VERSION=${SHLINK_VERSION}
ARG SHLINK_RUNTIME=rr
ENV SHLINK_RUNTIME=${SHLINK_RUNTIME}

ENV USER_ID='1001'
ENV PDO_SQLSRV_VERSION='5.13.0'
ENV MS_ODBC_DOWNLOAD='fae28b9a-d880-42fd-9b98-d779f0fdd77f'
ENV MS_ODBC_SQL_VERSION='18_18.5.1.1'
ENV MS_ODBC_SQL_SHA256='d7844123af31489d4fae825003d817ff17e920e27e986248850ff30325a7e1c7'
ENV LC_ALL='C'

WORKDIR /etc/shlink

# Install required PHP extensions
RUN \
    apk upgrade --no-cache && \
    # Temp install dev dependencies needed to compile the extensions \
    apk add --no-cache --virtual .dev-deps sqlite-dev postgresql-dev icu-dev libzip-dev zlib-dev linux-headers && \
    docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql intl calendar sockets bcmath zip && \
    apk add --no-cache sqlite-libs && \
    docker-php-ext-install -j"$(nproc)" pdo_sqlite && \
    # Remove temp dev extensions, and install prod equivalents that are required at runtime \
    apk del .dev-deps && \
    apk add --no-cache postgresql icu libzip libpng

# Install sqlsrv driver for x86_64 builds
RUN if [ $(uname -m) == "x86_64" ]; then \
      apk add --no-cache --virtual .phpize-deps ${PHPIZE_DEPS} unixodbc-dev && \
      wget https://download.microsoft.com/download/${MS_ODBC_DOWNLOAD}/msodbcsql${MS_ODBC_SQL_VERSION}-1_amd64.apk && \
      echo "${MS_ODBC_SQL_SHA256}  msodbcsql${MS_ODBC_SQL_VERSION}-1_amd64.apk" | sha256sum -c - && \
      apk add --no-cache --allow-untrusted msodbcsql${MS_ODBC_SQL_VERSION}-1_amd64.apk && \
      pecl install pdo_sqlsrv-${PDO_SQLSRV_VERSION} && \
      docker-php-ext-enable pdo_sqlsrv && \
      rm msodbcsql${MS_ODBC_SQL_VERSION}-1_amd64.apk && \
      apk del .phpize-deps; \
    fi

# Install shlink
FROM base AS builder
COPY . .
COPY --from=composer /usr/bin/composer ./composer.phar
RUN apk add --no-cache git && \
    php composer.phar install --no-dev --prefer-dist --optimize-autoloader --no-progress --no-interaction && \
    php composer.phar clear-cache && \
    rm -r docker composer.* && \
    sed -i "s/%SHLINK_VERSION%/${SHLINK_VERSION}/g" module/Core/src/Config/Options/AppOptions.php


# Prepare final image
FROM base
LABEL maintainer="Alejandro Celaya <alejandro@alejandrocelaya.com>"

COPY --from=builder --chown=${USER_ID} /etc/shlink .
COPY --from=roadrunner-builder --chown=${USER_ID} /out/rr bin/rr
RUN ln -s /etc/shlink/bin/cli /usr/local/bin/shlink && chmod +x bin/rr

# Expose default port
EXPOSE 8080

# Copy config specific for the image
COPY docker/docker-entrypoint.sh docker-entrypoint.sh
COPY docker/config/php.ini ${PHP_INI_DIR}/conf.d/

# Fail both architecture builds if PHP regresses or required drivers disappear.
RUN php -r 'if (PHP_VERSION_ID < 80512 || PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 5 || ini_get("session.upload_progress.enabled")) { throw new RuntimeException("Unsafe PHP runtime"); } foreach (["curl", "mbstring", "pdo_mysql", "pdo_pgsql", "pdo_sqlite", "intl", "calendar", "sockets", "bcmath", "zip"] as $extension) { if (!extension_loaded($extension)) { throw new RuntimeException("Missing extension: " . $extension); } } if (php_uname("m") === "x86_64" && !extension_loaded("pdo_sqlsrv")) { throw new RuntimeException("Missing SQL Server driver"); } echo "Verified PHP ", PHP_VERSION, " and required extensions", PHP_EOL;'

USER ${USER_ID}

ENTRYPOINT ["/bin/sh", "./docker-entrypoint.sh"]
