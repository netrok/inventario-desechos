# PHP-FPM sobre Rocky Linux 8, igual al SO del servidor de destino.
#
# Rocky 8 AppStream solo trae PHP hasta 8.1; el proyecto requiere ^8.2
# (docs/DEPLOY.md indica que se desarrolla y prueba sobre 8.4), así que se
# habilita el módulo PHP 8.4 del repositorio Remi — la misma fuente que se
# usaría en el servidor Rocky 8 real para tener PHP moderno.
FROM rockylinux:8

ARG PHP_STREAM=remi-8.4
ARG APP_UID=1000
ARG APP_GID=1000

RUN dnf -y install epel-release \
    && dnf -y install https://rpms.remirepo.net/enterprise/remi-release-8.rpm \
    && dnf -y module reset php \
    && dnf -y module enable php:${PHP_STREAM} \
    && dnf -y install \
        php php-cli php-fpm php-common \
        php-pdo php-pgsql \
        php-mbstring php-xml php-dom \
        php-gd php-zip php-bcmath php-opcache \
        php-intl php-process php-fileinfo \
        git unzip curl \
    && dnf clean all \
    && rm -rf /var/cache/dnf

# Composer (instalador oficial; misma vía que usarías a mano en el servidor).
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

# Extensiones requeridas por docs/DEPLOY.md sección 1:
#   pdo_pgsql, mbstring, ctype, tokenizer, xml, dom, gd (QR/Excel),
#   fileinfo (validación MIME de fotos/evidencias), zip (exportaciones XLSX).
# ctype y tokenizer vienen incluidas en php-common de Remi; se listan aquí
# como recordatorio de por qué el resto de paquetes están arriba.

# Usuario dedicado (no root) que coincide con el UID/GID típico del host,
# para que los archivos que el contenedor escriba en storage/ y
# bootstrap/cache no queden inaccesibles fuera del contenedor.
RUN groupadd -g ${APP_GID} laravel \
    && useradd -u ${APP_UID} -g laravel -m -s /bin/bash laravel

WORKDIR /var/www/html

COPY docker/php/php.ini /etc/php.d/99-inventario.ini
COPY docker/php/www.conf /etc/php-fpm.d/www.conf
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]
