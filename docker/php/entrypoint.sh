#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Directorio del PID de php-fpm: en Rocky 8 normalmente lo crea systemd al
# arrancar; aquí no hay systemd corriendo, así que lo creamos a mano.
mkdir -p /run/php-fpm

# El código llega por bind-mount desde el host; storage/ y bootstrap/cache/
# deben ser escribibles por el usuario del pool de php-fpm (laravel).
if [ -d storage ]; then
    chown -R laravel:laravel storage bootstrap/cache 2>/dev/null || true
fi

if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ no existe todavía. Primer arranque:"
    echo "[entrypoint]   docker compose exec app composer install"
    echo "[entrypoint] (o espera: este contenedor seguirá arrancando, pero Laravel"
    echo "[entrypoint]  no responderá hasta que corras ese comando una vez)."
fi

exec "$@"
