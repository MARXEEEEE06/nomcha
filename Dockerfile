FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod headers \
    && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY . /var/www/html

# Resume uploads are stored on disk by the app. Keep this path writable by Apache.
RUN mkdir -p /var/www/html/uploads/resumes \
    && chown -R www-data:www-data /var/www/html/uploads

EXPOSE 8080
