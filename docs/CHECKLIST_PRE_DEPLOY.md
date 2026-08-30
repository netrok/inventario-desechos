# Checklist pre-deploy — Inventario Desechos

Antes de publicar un release en producción (en especial el primer deploy del `fix/hardening-production`).

## Entorno

- [ ] PHP 8.4+ (requerido `^8.2`), `composer` disponible.
- [ ] Extensiones verificadas: `composer check-platform-reqs` (pdo_pgsql, mbstring, xml, dom, gd, fileinfo, zip, ctype, tokenizer).
- [ ] PostgreSQL alcanzable; únicamente recrearlo previo backup explícito.
- [ ] Node+npm disponibles para el build (solo build, no runtime).

## Variables y secretos

- [ ] `.env` creado en el servidor desde `.env.example`; `APP_KEY` generada en el servidor.
- [ ] `APP_ENV=production` y `APP_DEBUG=false`.
- [ ] `APP_URL` correcta (sin barra final), `SESSION_SECURE_COOKIE=true` tras configurar HTTPS.
- [ ] Credenciales de BD y MAIL solo en `.env`; NO en el repositorio (grep `DB_PASSWORD=` en tracked).
- [ ] `FILESYSTEM_DISK=public` y `storage:link` creado.

## Código y datos

- [ ] `composer install --no-dev --optimize-autoloader`.
- [ ] `npm ci && npm run build` (manifest debe existir en `public/build`).
- [ ] `php artisan migrate --force` (NUNCA `migrate:fresh`/`db:wipe`).
- [ ] Seed seguro: `RolesAndAdminSeeder` + `CatalogosBaseSeeder` (idempotentes). Admin solo si `SEED_ADMIN_EMAIL`+`SEED_ADMIN_PASSWORD` presentes.
- [ ] `php artisan storage:link` para fotos.

## Caches

- [ ] `php artisan config:cache`, `route:cache`, `view:cache` (los 3 probados OK).
- [ ] Tras tocar config/código: `php artisan optimize:clear && php artisan optimize`.

## Seguridad (verificación auditable)

- [ ] Rutas funcionales bajo `auth` + permiso (únicas públicas: login/recuperar y `/up`).
- [ ] Health check: `GET /up` responde `200 OK`.
- [ ] Cabeceras presentes en respuestas: `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`.
- [ ] HTTPS activo; CSP en web server (ver DEPLOY sección 6).
- [ ] Photos/evidencias servidas solo desde `public/storage` (montaje correcto).

## Calidad

- [ ] `php artisan test` (suite completa de regresión; base 304 tests / 1054 assertions / 0 failures).
- [ ] `php artisan migrate:status` sin pendientes (base 29 Ran / 0 Pending).
- [ ] `composer audit --no-dev` en 0 advisories y `npm audit --omit=dev` en 0 vulnerabilities (runtime limpio; los advisories residuales quedan solo en tooling de desarrollo: composer dev = phpunit/symfony-yaml, no bloquean el runtime).
- [ ] Backup previo al release (PostgreSQL + storage + .env) — ver BACKUP_RESTORE.

## Post-deploy

- [ ] Login real, creación/movimiento de item, una venta en POS y ticket 80mm/58mm.
- [ ] `php artisan about` / logs sin errores inesperados.
- [ ] Plan de rollback documentado (DEPLOY sección 8).