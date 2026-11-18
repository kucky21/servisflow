#!/bin/sh
# ServisHub – vstupní bod PHP kontejneru.
# Připraví adresáře, nainstaluje závislosti (dev), počká na databázi,
# spustí migrace a v dev prostředí volitelně naplní demo data.
set -e

cd /var/www/html

if [ "$1" = "php-fpm" ]; then
    mkdir -p temp/cache temp/sessions temp/mpdf log

    if [ "${APP_ENV:-production}" != "production" ]; then
        echo "[servishub] composer install (dev)"
        composer install --no-interaction --prefer-dist --no-progress
    fi

    echo "[servishub] čekám na PostgreSQL ${DB_HOST}:${DB_PORT:-5432}"
    until pg_isready -h "${DB_HOST}" -p "${DB_PORT:-5432}" -U "${DB_USER}" -q; do
        sleep 1
    done

    # Smazání cache DI kontejneru, aby se projevily změny konfigurace
    rm -rf temp/cache/nette.configurator temp/cache/latte 2>/dev/null || true

    echo "[servishub] spouštím databázové migrace"
    php bin/console migrations:continue

    if [ "${APP_ENV:-production}" != "production" ] && [ "${SEED_DEMO_DATA:-0}" = "1" ]; then
        echo "[servishub] demo data (pouze pokud je databáze prázdná)"
        php bin/console app:seed --if-empty
    fi

    chown -R www-data:www-data temp log 2>/dev/null || true
    chmod -R ug+rwX temp log 2>/dev/null || true
fi

exec "$@"
