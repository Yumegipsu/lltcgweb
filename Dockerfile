FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    libsqlite3-dev \
    curl \
    && docker-php-ext-install pdo_sqlite \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache.conf /etc/apache2/conf-available/lltcgweb.conf
RUN a2enconf lltcgweb

WORKDIR /var/www/html

ENV TCG_SYNC_ENABLED=0 \
    TCG_CORS_ORIGINS=https://loveliveradio.ca,http://localhost:8080

EXPOSE 80
