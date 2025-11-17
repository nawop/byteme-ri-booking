# PHP + Apache
FROM php:8.2-apache

# Enable useful Apache modules
RUN a2enmod rewrite headers

# PHP extensions (sqlite3/pdo_sqlite)
RUN docker-php-ext-install pdo_sqlite

# Set document root
ENV APACHE_DOCUMENT_ROOT=/var/www/html/ri-booking
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf

# Copy app
WORKDIR /var/www/html
COPY . /var/www/html/

# Entrypoint preps disk/db then starts Apache
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Permissions (Apache runs as www-data)
RUN chown -R www-data:www-data /var/www/html

EXPOSE 8080
CMD ["docker-entrypoint.sh"]
