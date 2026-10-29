# Vigil in one container: Apache serves the dashboard and status page,
# and a background worker runs the checks. Data lives in /var/www/vigil/storage.
FROM php:8.3-apache

RUN a2enmod rewrite headers \
 && sed -ri 's#/var/www/html#/var/www/vigil/public#g' /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf \
 && sed -ri 's#AllowOverride None#AllowOverride All#g' /etc/apache2/apache2.conf \
 && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf && a2enconf servername

WORKDIR /var/www/vigil
COPY . .
RUN mkdir -p storage && chown -R www-data:www-data storage

ENV VIGIL_DATABASE=/var/www/vigil/storage/vigil.sqlite
VOLUME ["/var/www/vigil/storage"]
EXPOSE 80

# Start the check worker as www-data next to Apache.
CMD ["sh", "-c", "su -s /bin/sh www-data -c 'php /var/www/vigil/bin/vigil work' & exec apache2-foreground"]
