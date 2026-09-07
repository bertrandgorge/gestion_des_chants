#!/usr/bin/env bash
#
# Recharge la base de données de développement (Docker) depuis un dump SQL.
#
# Usage :
#   bin/import_dump.sh <fichier.sql>
#
# Étapes :
#   1. lit le nom de la base dans config.php (via le conteneur « app ») ;
#   2. DROP puis CREATE de la base — remise à zéro complète — sur le conteneur « db » ;
#   3. injecte le dump ;
#   4. rejoue bin/migrate.php pour rattraper d'éventuelles migrations plus
#      récentes que le dump (idempotent).
#
# Le dump attendu est un export complet (phpMyAdmin / mysqldump) sans instruction
# CREATE DATABASE / USE : il est chargé dans la base nommée par config.php.

set -euo pipefail

DUMP="${1:-}"
if [[ -z "$DUMP" ]]; then
    echo "Usage : $0 <fichier.sql>" >&2
    exit 1
fi
if [[ ! -f "$DUMP" ]]; then
    echo "Fichier introuvable : $DUMP" >&2
    exit 1
fi

cd "$(dirname "$0")/.."

DC="docker compose"

# Mot de passe root : cf. docker-compose.yml (MYSQL_ROOT_PASSWORD), surchargeable.
ROOT_PWD="${MYSQL_ROOT_PASSWORD:-root}"
mysql_root() { $DC exec -T -e "MYSQL_PWD=$ROOT_PWD" db mysql -uroot "$@"; }

if ! mysql_root -e 'SELECT 1' >/dev/null 2>&1; then
    echo "Le conteneur « db » ne répond pas. Lancez d'abord : docker compose up -d" >&2
    exit 1
fi

DB_NAME="$($DC exec -T app php -r '$c = require "config.php"; echo $c["db"]["name"];' 2>/dev/null | tr -d '\r')"
DB_NAME="${DB_NAME:-gestion_des_chants}"

echo "→ Base cible : $DB_NAME"
echo "→ Dump       : $DUMP ($(du -h "$DUMP" | cut -f1))"

echo "→ Remise à zéro de la base…"
mysql_root -e "DROP DATABASE IF EXISTS \`$DB_NAME\`;
               CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "→ Chargement du dump…"
mysql_root "$DB_NAME" < "$DUMP"

echo "→ Migrations (rattrapage)…"
$DC exec -T app php bin/migrate.php

echo "✓ Base « $DB_NAME » rechargée depuis $DUMP"
