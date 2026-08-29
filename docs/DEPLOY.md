# Deploy — Inventario Desechos

Guía de implementación en producción sobre `fix/hardening-production`.

> Regla de oro: `migrate:fresh` y `db:wipe` están **PROHIBIDOS** en producción.
> Nunca subas `.env` al repositorio. Haz backup antes de cualquier release (ver [BACKUP_RESTORE.md](BACKUP_RESTORE.md)).

---

## 1. Requisitos

- Linux (recomendado Debian/Ubuntu o RHEL).
- **PHP 8.4** (el proyecto requiere `^8.2`; se desarrolla y prueba sobre 8.4) con `composer`.
- **PostgreSQL** compatible (docker local usa 16+).
- **Node.js + npm** (solo para compilar assets en el build; no se sirve Node en runtime).
- Servidor web: Nginx (recomendado) o Apache.
- Aplicación en modo producción: `APP_ENV=production` y `APP_DEBUG=false` **obligatorios** (sección 2). Con `false`, `config:cache` es seguro (sin `env()` fuera de `config/`).
- Extensiones PHP necesarias (verificar con `composer check-platform-reqs`):
  - `pdo_pgsql` (PostgreSQL)
  - `mbstring`, `ctype`, `tokenizer`, `xml`, `dom`
  - `gd` (QR de etiquetas y Excel)
  - `fileinfo` (validación MIME de fotos/evidencias)
  - `zip` (exportaciones XLSX)
  - `php-cli` para `artisan`

## 2. Variables de entorno de producción (`.env`)

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tu-dominio.example        # obligatorio, sin barra final
APP_LOCALE=es

LOG_LEVEL=warning                          # o error; `debug` NO en producción
LOG_CHANNEL=stack

DB_CONNECTION=pgsql
DB_HOST=<host>
DB_PORT=5432
DB_DATABASE=<db>
DB_USERNAME=<usuario>
DB_PASSWORD=<password-seguro>             # SOLO en .env, nunca en el repo

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true                # SOLO detrás de HTTPS
SESSION_SAME_SITE=lax

CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=public                     # fotos/evidencias

MAIL_MAILER=smtp                          # configurar transporte real
```

> `APP_KEY` se genera en el servidor con `php artisan key:generate`; nunca reutilices una de desarrollo.

## 3. Instalación (reproducible)

```bash
git clone <repo> /var/www/inventario-desechos
cd /var/www/inventario-desechos

# Puede correr como usuario de servicio (www-data, deployer...)
composer install --no-dev --optimize-autoloader

npm ci
npm run build

cp .env.example .env          # luego editar (sección 2)
php artisan key:generate

php artisan migrate --force

# Seed SEGURO: primera vez crea roles/permisos + Admin (solo si das ambas variables)
php artisan db:seed --class=RolesAndAdminSeeder --force

php artisan db:seed --class=CatalogosBaseSeeder --force

php artisan storage:link

php artisan optimize           # config + route + event + view cache
```

### Crear el primer Admin SIN guardar contraseña en Git

1. Definir `SEED_ADMIN_EMAIL` y `SEED_ADMIN_PASSWORD` **fuera del repositorio** (barril del shell del deploy, gestor de secretos o el `.env` del servidor — el `.env` NO se versiona).
2. Ejecutar el seeder con esas variables disponibles. Ejemplo desde el shell:

   ```bash
   SEED_ADMIN_EMAIL="admin@tudominio.example" \
   SEED_ADMIN_PASSWORD="$PASAJERO_ADMIN_ALFANUMERICA_12mas" \
     php artisan db:seed --class=RolesAndAdminSeeder --force
   ```

   > `$PASAJERO_ADMIN_ALFANUMERICA_12mas` es aquí solo un marcador: provee la clave real por shell/gestor de secretos, nunca hardcodeada.
3. Verificar el login con ese usuario.
4. **Retirar `SEED_ADMIN_PASSWORD` del entorno** si ya no se necesita (lo más pronto posible). El seeder no la vuelve a necesitar para los seeds siguientes.
5. Regenerar la cache de configuración si las variables vivían en `.env`: `php artisan optimize:clear && php artisan config:cache`.

### Notas sobre el seed del Admin

- `RolesAndAdminSeeder` es **idempotente** (`firstOrCreate` + `syncPermissions`): roles/permisos se sincronizan en cada corrida sin duplicar; NO sobrescribe la contraseña de un Admin existente.
- Comportamiento de configuración (verificado por tests `SeederAdminTest`):
  - `SEED_ADMIN_EMAIL` **y** `SEED_ADMIN_PASSWORD` presentes (≥12 caracteres) → se crea/mantiene el Admin inicial con rol `Admin`.
  - Ambas ausentes → roles/permisos se siembran y **no se toca a ningún usuario**.
  - Solo una presente → el seeder lanza error claro.
- El seeder lee `config('seeding.*')` (no `env()` directo), por lo que funciona tanto con `config:cache` activo como sin él.
- Cambia la contraseña del Admin después del primer ingreso si lo deseas.

## 4. Permisos y storage

```bash
mkdir -p storage/framework/{sessions,views,cache} storage/logs
chown -R <usuario-web>:<grupo-web> storage bootstrap/cache public/build
chmod -R 775 storage bootstrap/cache
```

`storage:link` expone fotos en `public/storage`. Si el servidor ya no permite symlinks, copia `storage/app/public` igualmente (documentar mantener sincronizado).

## 5. Laravel caches

El proyecto soporta los tres caches (probados durante el hardening):

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Tras cambios en código/config en releases nuevos:

```bash
php artisan optimize:clear
php artisan optimize
```

`route:cache` funciona (no hay closures incompatibles). `config:cache` es seguro: el único `env()` fuera de `config/` histórico (seeder Admin) se migró a `config('seeding.*')`.

## 6. Servidor web

Nginx (TLS). Ejemplo mínimo:

```nginx
server {
    listen 443 ssl http2;
    server_name tu-dominio.example;

    ssl_certificate     /etc/letsencrypt/live/tu-dominio.example/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/tu-dominio.example/privkey.pem;

    root /var/www/inventario-desechos/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ /\.(?!well-known).* { deny all; }

    # CSP recomendada (ajusta a tu stack): NO rompe la UI con inline de Vite/Blade
    add_header Content-Security-Policy "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self';" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
}
```

La app ya envía `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN` y `Referrer-Policy`; duplicarlas en el web server es inofensivo. HTTPS es **requisito** en producción; con `SESSION_SECURE_COOKIE=true` las cookies viajan solo por TLS.

**HSTS** (`Strict-Transport-Security`) queda a decisión del equipo y **solo** debe activarse cuando HTTPS esté funcionando correctamente en todo el dominio, nunca sobre HTTP. Nginx: `add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;`. No recomendado en entornos mixtos HTTP/HTTPS.

## 7. Colas y correo

Hoy la app no depende de colas para el flujo principal; `QUEUE_CONNECTION=database` y un worker opcional:

```bash
php artisan queue:work --tries=3 --sleep=1
```

Gestionar con systemd (documentar fuera del repo). El correo (`MAIL_MAILER`) solo se usa para recuperación de contraseña.

## 8. Rollback de aplicación

- Mantener el release en un directorio versionado y conmutar el symlink de `current`.
- Pasos: `php artisan down` (mantenimiento) → reemplazar código → `composer install --no-dev --optimize-autoloader` → `npm ci && npm run build` → `php artisan migrate --force` → `php artisan optimize` → `php artisan up`.
- Si una migración falla a mitad: restaurar la BD desde el backup previo (ver BACKUP_RESTORE) y reintentar tras corregir.
- En PostgreSQL, las migraciones de Laravel son transaccionales por tabla cuando fallan; ante incertidumbre, restaurar el backup.

## 9. Backup

Ver [BACKUP_RESTORE.md](BACKUP_RESTORE.md). Resumen: `pg_dump -Fc` + copia de `storage/app` + `.env` fuera del repo, con `.pgpass`/prompt (nunca contraseña en el comando ni en el repo).