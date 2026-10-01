# Only for hosts that run PHP through a container (e.g. Render "Web Service").
# On normal PHP hosting use release/submittal-review-laravel.zip instead (see DEPLOY.md).
FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
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
    && chown -R www-data:www-data storage bootstrap/cache .env

# Render (and similar) tell the app which port to listen on in $PORT
CMD ["sh", "-c", "sed -i \"s/^Listen 80$/Listen ${PORT:-80}/\" /etc/apache2/ports.conf && sed -i \"s/<VirtualHost \\*:80>/<VirtualHost *:${PORT:-80}>/\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]
