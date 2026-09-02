#!/bin/bash
set -e

cd /var/www/html

# config.php de développement si absent
if [ ! -f config.php ]; then
    echo "→ Copie config.docker.php vers config.php"
    cp config.docker.php config.php
fi

# Dépendances Composer
if [ ! -d vendor ]; then
    echo "→ composer install"
    composer install --no-interaction --prefer-dist
fi

mkdir -p storage/cache public/assets/uploads/logos
chown -R www-data:www-data storage public/assets/uploads

# Attente de MySQL
echo "→ Attente de la base de données..."
until php -r '
    $c = require "config.php";
    try { new PDO("mysql:host={$c["db"]["host"]};dbname={$c["db"]["name"]}", $c["db"]["user"], $c["db"]["pass"]); exit(0); }
    catch (Throwable $e) { exit(1); }
'; do
    sleep 2
done

echo "→ Migrations"
php bin/migrate.php

exec "$@"
