# PostgreSQL 16 sobre Rocky Linux 8, vía el repositorio oficial PGDG —
# la misma fuente que usarías para instalar Postgres 16 en el servidor Rocky 8
# real (el AppStream de Rocky 8 no trae Postgres 16 nativo).
FROM rockylinux:8

ARG PG_MAJOR=16

RUN dnf -y install https://download.postgresql.org/pub/repos/yum/reporpms/EL-8-x86_64/pgdg-redhat-repo-latest.noarch.rpm \
    && dnf -qy module disable postgresql \
    && dnf -y install postgresql${PG_MAJOR}-server postgresql${PG_MAJOR}-contrib \
    && dnf clean all \
    && rm -rf /var/cache/dnf

ENV PG_BIN=/usr/pgsql-16/bin
ENV PGDATA=/var/lib/pgsql/16/data
ENV PATH="${PG_BIN}:${PATH}"

RUN mkdir -p "${PGDATA}" && chown -R postgres:postgres /var/lib/pgsql

COPY --chown=postgres:postgres docker/postgres/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER postgres
WORKDIR /var/lib/pgsql

VOLUME ["/var/lib/pgsql/16/data"]
EXPOSE 5432

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
