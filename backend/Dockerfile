FROM php:8.3-apache

# Extensiones: pdo_mysql para MariaDB y phpredis para Redis
RUN docker-php-ext-install pdo_mysql \
    && pecl install redis-6.1.0 \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear

# Todas las rutas que no sean archivos se atienden con index.php (router de la API)
RUN echo 'FallbackResource /index.php' > /etc/apache2/conf-enabled/api-router.conf \
    && echo 'ServerName backend' > /etc/apache2/conf-enabled/servername.conf \
    && printf '<Directory /var/www/html/lib>\n  Require all denied\n</Directory>\n' > /etc/apache2/conf-enabled/deny-lib.conf

COPY src/ /var/www/html/

EXPOSE 80
