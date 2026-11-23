FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY public/ public/
COPY src/ src/
COPY test-db.php ./
COPY docker-start.sh /usr/local/bin/mdrrmo-start

RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|' /etc/apache2/sites-available/000-default.conf \
    && printf '%s\n' 'ServerName localhost' '<Directory /var/www/html/public>' \
         'Options -Indexes' 'CGIPassAuth On' '</Directory>' > /etc/apache2/conf-available/mdrrmo.conf \
    && a2enconf mdrrmo \
    && chmod +x /usr/local/bin/mdrrmo-start

ENV PORT=10000
EXPOSE 10000
CMD ["/usr/local/bin/mdrrmo-start"]
