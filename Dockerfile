FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev \
    && docker-php-ext-install -j"$(nproc)" curl mbstring pdo_mysql \
    && a2enmod rewrite \
    && printf '%s\n' \
        '<Directory /var/www/html>' \
        '    AllowOverride All' \
        '    Require all granted' \
        '</Directory>' \
        > /etc/apache2/conf-available/tutalink.conf \
    && a2enconf tutalink \
    && sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf \
    && printf '%s\n' \
        'upload_max_filesize=26M' \
        'post_max_size=28M' \
        'max_execution_time=60' \
        > /usr/local/etc/php/conf.d/tutalink.ini \
    && mkdir -p /var/lib/tutalink-private/provider-documents /var/lib/tutalink-private/revision-materials \
    && chown -R www-data:www-data /var/lib/tutalink-private \
    && chmod -R 700 /var/lib/tutalink-private \
    && rm -rf /var/lib/apt/lists/*

ENV TUTALINK_ENV=production
ENV TUTALINK_PRIVATE_STORAGE_DIR=/var/lib/tutalink-private

WORKDIR /var/www/html
COPY . /var/www/html

EXPOSE 8080
CMD ["apache2-foreground"]
