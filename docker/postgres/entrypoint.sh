#!/usr/bin/env bash
# Entrypoint mínimo para Postgres empaquetado vía RPM (PGDG) en Rocky 8.
# Las imágenes oficiales de postgres en Docker Hub (Debian) traen este
# comportamiento de fábrica; el RPM de PGDG no, así que se reimplementa
# aquí lo esencial: inicializar el cluster una sola vez y crear la BD/rol
# de la app a partir de variables de entorno.
set -euo pipefail

: "${POSTGRES_DB:?POSTGRES_DB no definido}"
: "${POSTGRES_USER:?POSTGRES_USER no definido}"
: "${POSTGRES_PASSWORD:?POSTGRES_PASSWORD no definido}"

PGBIN="${PG_BIN:-/usr/pgsql-16/bin}"
export PGDATA="${PGDATA:-/var/lib/pgsql/16/data}"

if [ ! -s "${PGDATA}/PG_VERSION" ]; then
    echo "[entrypoint] Inicializando cluster en ${PGDATA}..."
    "${PGBIN}/initdb" -D "${PGDATA}" -U postgres --auth=trust --encoding=UTF8 >/tmp/initdb.log 2>&1

    {
        echo "listen_addresses = '*'"
        echo "password_encryption = scram-sha-256"
    } >> "${PGDATA}/postgresql.conf"

    # Password auth desde la red de Docker; trust solo local (para el propio
    # arranque). Ajusta el CIDR si tu red de compose usa otro rango.
    echo "host all all 0.0.0.0/0 scram-sha-256" >> "${PGDATA}/pg_hba.conf"

    "${PGBIN}/pg_ctl" -D "${PGDATA}" -w start -o "-c listen_addresses=''"

    "${PGBIN}/psql" -v ON_ERROR_STOP=1 --username postgres <<-SQL
        CREATE ROLE "${POSTGRES_USER}" WITH LOGIN PASSWORD '${POSTGRES_PASSWORD}' CREATEDB;
        CREATE DATABASE "${POSTGRES_DB}" OWNER "${POSTGRES_USER}";
SQL

    if [ -n "${POSTGRES_EXTRA_DB:-}" ]; then
        echo "[entrypoint] Creando base adicional ${POSTGRES_EXTRA_DB} (testing)..."
        "${PGBIN}/psql" -v ON_ERROR_STOP=1 --username postgres \
            -c "CREATE DATABASE \"${POSTGRES_EXTRA_DB}\" OWNER \"${POSTGRES_USER}\";"
    fi

    "${PGBIN}/pg_ctl" -D "${PGDATA}" -m fast -w stop
    echo "[entrypoint] Cluster listo."
else
    echo "[entrypoint] Cluster existente detectado en ${PGDATA}, se omite inicialización."
fi

exec "${PGBIN}/postgres" -D "${PGDATA}"
