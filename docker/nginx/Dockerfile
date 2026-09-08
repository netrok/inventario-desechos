# Nginx sobre Rocky Linux 8, igual al servidor web recomendado en
# docs/DEPLOY.md sección 1 ("Nginx (recomendado) o Apache").
#
# Se usa el módulo nginx del propio AppStream de Rocky 8 (nginx:1.24) en vez
# de un repo de terceros: es la vía "nativa" que usarías en el servidor real
# con `dnf module enable nginx:1.24 && dnf install nginx`. Si tu servidor de
# destino usa otra versión, ajusta el valor de NGINX_STREAM.
FROM rockylinux:8

ARG NGINX_STREAM=1.24

RUN dnf -y install epel-release \
    && dnf -y module enable nginx:${NGINX_STREAM} \
    && dnf -y install nginx \
    && dnf clean all \
    && rm -rf /var/cache/dnf

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
