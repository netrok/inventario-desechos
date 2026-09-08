# Entorno Docker con paridad Rocky Linux 8 — inventario-desechos

Este `docker-compose.yml` reemplaza al enfoque anterior basado en Laravel Sail: en vez de contenedores genéricos (Debian/Alpine), cada servicio corre sobre **Rocky Linux 8**, el mismo sistema operativo del servidor donde vas a desplegar el proyecto después. La idea es que lo que pruebes aquí en Windows se comporte igual que en el servidor real — mismas versiones de PHP, Postgres y Nginx, instaladas desde los mismos repos que usarías a mano en ese servidor.

## Qué construye cada pieza y por qué

| Servicio | Base | Fuente del paquete | Por qué |
|---|---|---|---|
| `app` (PHP-FPM) | `rockylinux:8` | Repo **Remi**, módulo `php:remi-8.4` | El AppStream nativo de Rocky 8 solo llega a PHP 8.1; el proyecto requiere `^8.2` y `docs/DEPLOY.md` indica que se prueba sobre 8.4. Remi es la fuente estándar para esto en RHEL/Rocky/Alma. |
| `webserver` (Nginx) | `rockylinux:8` | Módulo AppStream `nginx:1.24` | Es la vía "nativa" en Rocky 8 (`dnf module enable nginx:1.24`), sin repos de terceros. Si tu servidor real usa otra versión, cambia `NGINX_STREAM` en `docker/nginx/Dockerfile`. |
| `db` (PostgreSQL) | `rockylinux:8` | Repo oficial **PGDG**, `postgresql16-server` | Rocky 8 AppStream no trae Postgres 16; PGDG es la fuente oficial recomendada por el propio proyecto PostgreSQL para instalarlo en RHEL/Rocky. Coincide con "docker local usa 16+" de `docs/DEPLOY.md`. |
| `node` | `node:20-alpine` (NO Rocky 8) | — | Node **nunca corre en el servidor real** — `docs/DEPLOY.md` es explícito: "solo para compilar assets en el build; no se sirve Node en runtime". Por eso este contenedor no necesita igualar el SO de producción; es una herramienta de desarrollo, no parte del despliegue. |

A diferencia de la imagen oficial de Postgres en Docker Hub (que trae de fábrica toda la lógica de "inicializar el cluster la primera vez"), el RPM de PGDG no incluye eso — por lo que `docker/postgres/entrypoint.sh` la reimplementa: crea el cluster solo si no existe, crea el rol y la base de la app, y además una base `_test` (mismo espíritu que `docs/ENTORNOS_DEMO_TEST.md`: dev y testing separados).

**Importante — esto no reemplaza `docs/DEPLOY.md`.** El servidor real seguirá instalándose a mano (o con tu herramienta de automatización) siguiendo esa guía; este Docker es para que desarrolles y valides localmente contra el mismo SO/versiones antes de tocar el servidor. El código llega a los contenedores por bind-mount (para iterar rápido), no se "hornea" dentro de la imagen como sí harías en un despliegue real.

## 1. Coloca estos archivos en el proyecto

Copia toda la carpeta `docker/` y el archivo `docker-compose.yml` a la raíz de `inventario-desechos` (junto a `composer.json`), dentro de tu WSL Ubuntu (`~/code/inventario-desechos/`). Si ya habías puesto ahí el `compose.yaml` de Sail de la vez pasada, no pasa nada por tenerlo — simplemente no lo uses (`docker compose -f docker-compose.yml ...` es explícito), o bórralo si no lo vas a usar.

## 2. Configura `.env`

```bash
cd ~/code/inventario-desechos
cp .env.example .env
id -u   # anótalo
id -g   # anótalo
```

Edita `.env` y ajusta/agrega:

```env
APP_ENV=local

# UID/GID de tu usuario en WSL, para que storage/ y bootstrap/cache queden
# escribibles también desde fuera del contenedor.
APP_UID=1000
APP_GID=1000

# Puertos expuestos hacia Windows (cambia si algo ya usa el 8080/5173/5432).
APP_PORT=8080
FORWARD_DB_PORT=5432
VITE_PORT=5173

DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=inventario_desechos
DB_USERNAME=inventario
DB_PASSWORD=secret
```

`DB_HOST=db` es el nombre del servicio en `docker-compose.yml` — Docker resuelve ese nombre dentro de la red `inventario` automáticamente, no es un hostname real.

## 3. Construir (esto sí lo corres en tu máquina, no aquí)

```bash
docker compose build
```

La primera vez tarda varios minutos: cada imagen instala su stack completo de paquetes RPM desde cero (no hay una imagen "rockylinux+php" prearmada como con Sail, aquí la armamos nosotros). Si algo falla descargando un RPM, corre el build de nuevo — los repos de Remi/PGDG a veces tienen mirrors lentos.

## 4. Levantar los contenedores

```bash
docker compose up -d
docker compose --profile dev up -d node    # aparte, porque node solo aplica en dev
```

Verifica que los tres estén sanos:

```bash
docker compose ps
```

`db` debe mostrar `healthy` antes de que `app` termine de arrancar (así está encadenado con `depends_on`).

## 5. Preparar la aplicación (primera vez)

```bash
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force

docker compose exec app php artisan db:seed --class=RolesAndAdminSeeder --force
docker compose exec app php artisan db:seed --class=CatalogosBaseSeeder --force

docker compose exec app php artisan storage:link
```

Para tu primer Admin con contraseña conocida (igual que en `docs/DEPLOY.md`):

```bash
docker compose exec -e SEED_ADMIN_EMAIL="admin@local.test" -e SEED_ADMIN_PASSWORD="CambiaEsto123!" \
  app php artisan db:seed --class=RolesAndAdminSeeder --force
```

## 6. Frontend

Con el contenedor `node` levantado (paso 4), ya está corriendo `npm run dev` con hot-reload. Para una build de producción puntual, en vez de dejarlo corriendo:

```bash
docker compose run --rm node npm run build
```

Abre **http://localhost:8080** (o el puerto que hayas puesto en `APP_PORT`).

## 7. Un ajuste necesario para correr los tests

Igual que te comenté con el enfoque de Sail: `phpunit.xml` trae `DB_HOST` fijo en `127.0.0.1`. Aquí la base vive en el contenedor `db`, así que cámbialo a:

```xml
<!-- phpunit.xml -->
<env name="DB_HOST" value="db" />
```

Y corre:

```bash
docker compose exec app php artisan test
```

## Comandos del día a día

```bash
docker compose up -d            # levantar
docker compose down             # apagar (conserva la BD, es un volumen)
docker compose exec app php artisan tinker
docker compose exec app php artisan migrate:status
docker compose logs -f app      # logs de PHP-FPM
docker compose logs -f webserver
docker compose exec app vendor/bin/pint --test
```

## Diferencias a tener presentes frente al servidor real

- Aquí el código está montado por bind-mount (edición instantánea); en el servidor real se despliega con `git pull` + `composer install --no-dev` dentro de la propia máquina, sin contenedores (`docs/DEPLOY.md`).
- `opcache.validate_timestamps=1` en `docker/php/php.ini` es cómodo para desarrollo (detecta cambios de archivo sin reiniciar). En el servidor real, con `config:cache` de por medio, `docs/DEPLOY.md` asume que ese valor va en `0` tras cada deploy.
- El `pg_hba.conf` que genera `docker/postgres/entrypoint.sh` acepta conexiones con password desde cualquier IP de la red de Docker (`0.0.0.0/0` dentro de esa red aislada) — está bien para un contenedor que solo es alcanzable desde tu propia máquina; en el servidor real ese archivo se configura con reglas más estrictas.
