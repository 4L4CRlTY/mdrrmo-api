#!/bin/sh
set -eu

tries=0
until php /var/www/html/test-db.php --initialize; do
    tries=$((tries + 1))
    if [ "$tries" -ge 10 ]; then
        exit 1
    fi
    sleep 3
done

sed -i "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/\*:80>/*:${PORT}>/" /etc/apache2/sites-available/000-default.conf
exec apache2-foreground
