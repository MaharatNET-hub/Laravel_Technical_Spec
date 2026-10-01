# Only for hosts that run PHP through a container (e.g. Render "Web Service").
# On normal PHP hosting use release/submittal-review-laravel.zip instead (see DEPLOY.md).
FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends git unzip libpq-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && printf 'upload_max_filesize=8M\npost_max_size=8M\nmemory_limit=512M\nmax_execution_time=120\ndisplay_errors=Off\nlog_errors=On\n' > /usr/local/etc/php/conf.d/app.ini
RUN curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && cp -n .env.example .env \
    && mkdir -p storage/app/demo/sample storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache database .env

# Render (and similar) give the port in $PORT. The free plan starts with empty files on every restart,
# so the database is migrated and the seeders restore the demo (v1 sample review, v2 admin + site content) in the background.
CMD ["sh", "-c", "sed -i \"s/^Listen 80$/Listen ${PORT:-80}/\" /etc/apache2/ports.conf && sed -i \"s/<VirtualHost \\*:80>/<VirtualHost *:${PORT:-80}>/\" /etc/apache2/sites-available/000-default.conf && (su www-data -s /bin/sh -c 'php artisan v2:install && php artisan db:seed --force' &) && exec apache2-foreground"]
