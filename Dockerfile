# PHP 8.2 Alpine for development and tests
FROM php:8.2-cli-alpine

RUN apk add --no-cache \
    git \
    unzip \
    bash \
    libzip-dev \
    libsodium-dev \
    postgresql-dev

# pdo_mysql / pdo_pgsql: the real-database tests (phpunit --group database, 1.6).
RUN docker-php-ext-install -j$(nproc) zip sodium pdo_mysql pdo_pgsql

RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN git config --global --add safe.directory /app

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="/app/vendor/bin:${PATH}"
