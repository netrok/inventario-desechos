# Backup / Restore — Inventario Desechos

La copia de seguridad debe incluir **tres piezas**:

1. **PostgreSQL** (datos: items, movimientos, ventas, usuarios, permisos...).
2. **`storage/app`** (fotos de items y evidencias de movimientos — la BD solo guarda rutas).
3. **`.env` / configuración real** (fuera del repositorio; NO se versiona).

> Una copia solo de BD NO es suficiente: sin `storage/app` las fotos/evidencias quedan huérfanas.

---

## Backup PostgreSQL

Exportación lógica en formato comprimido (`-Fc`), que conserva tipos y permite `pg_restore`.

```bash
VERSION=$(date +%Y%m%d_%H%M%S)
pg_dump \
  -Fc \
  -h <DB_HOST> \
  -p <DB_PORT> \
  -U <DB_USERNAME> \
  -d <DB_DATABASE> \
  -f "inventario_desechos_${VERSION}.dump"
```

**Contraseña**: usa `~/.pgpass` (recomendado), `PGPASSWORD` solo en casos puntuales y entendiendo que queda en el shell/historial, o el prompt interactivo de `pg_dump`. **NUNCA** pongas la contraseña en el comando ni en scripts versionados.

Backup de storage:

```bash
rsync -a storage/app/ backups/inventario_desechos_storage_${VERSION}/
```

## Restore PostgreSQL

Restaurar carga datos **sobre una base**: hazlo SIEMPRE primero en una BD separada.

```bash
# 1) Crear la BD vacía (si no existe)
createdb -h <DB_HOST> -p <DB_PORT> -U <DB_USERNAME> -T template0 <DB_DATABASE>

# 2) Restaurar el dump (con el usuario dueño, como `postgres` o el de la app)
pg_restore \
  -h <DB_HOST> -p <DB_PORT> -U <DB_USERNAME> \
  -d <DB_DATABASE> \
  --no-owner \
  --no-privileges \
  "inventario_desechos_<timestamp>.dump"
```

Alternativa simple (restaura y recrea): si trabajas sobre una BD que se va a reemplazar, primero `dropdb`/`createdb` en mantenimiento de la app.

Restore de storage:

```bash
rsync -a backups/inventario_desechos_storage_${VERSION}/ storage/app/
```

### Advertencias

- **`restore` reemplaza/carga datos.** Verifica primero en una base separada.
- Detén la app o ponla en mantenimiento (`php artisan down`) durante la restauración.
- Tras restaurar: `php artisan migrate:status` y `php artisan config:cache` (validar consistencia de esquema) y probar login de un usuario real.
- Preserva permisos de archivos de fotos (`chown`/`chmod` acordes al usuario web).
- El dump NO incluye objetos fuera de esa base (usuarios/roles de PostgreSQL). Documenta el rol de la app.

---

## Consistencia mínima (checklist de respaldo)

```text
[ ] pg_dump -Fc del día (retener N días)
[ ] rsync/copia de storage/app
[ ] copia del .env real (cifrada o en gestor de secretos)
[ ] prueba de restauración periódica en BD separada
[ ] verificar que el dump y el storage sean del mismo instante (ideal: ventana corta)
```