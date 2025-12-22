# ./Dockerfile.php
FROM php:8.3-cli

RUN echo "memory_limit=1024M" > /usr/local/etc/php/conf.d/zz-memory-limit.ini
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip gcc make autoconf \
 && docker-php-ext-install pdo_mysql \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /workspace

