FROM php:8.2-apache

RUN a2enmod rewrite headers

# Install Postgres PDO driver
RUN apt-get update && apt-get install -y libpq-dev \
  && docker-php-ext-install pdo_pgsql

WORKDIR /var/www/html
COPY . /var/www/html/

# (document root config as before if you used it)
ENV APACHE_DOCUMENT_ROOT=/var/www/html/
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf

RUN chown -R www-data:www-data /var/www/html

EXPOSE 8080

CMD ["apache2-foreground"]
