# Inventario Desechos (Laravel)

Sistema web de **inventario y ventas** para equipos/desechos tecnológicos. Controla el ciclo de vida de cada equipo (`Item`), su trazabilidad completa (movimientos), catálogos (categorías/ubicaciones), acceso por roles, un Mini POS con ventas atómicas y tickets térmicos imprimibles.

---

## Stack

- Laravel 12
- PHP 8.4+
- PostgreSQL (numeración por *sequences*, dinero `numeric(12,2)` sin float)
- Blade + Tailwind (Vite)
- Spatie Permission (roles/permisos)
- Laravel Breeze (autenticación)
- DomPDF / Maatwebsite Excel (reportes PDF/XLSX)

---

## Módulos existentes

| Módulo | Descripción |
|---|---|
| **Dashboard** | KPIs: total por estado, top ubicaciones/categorías, últimos movimientos. |
| **Items** | Alta/edición/consulta con `codigo` autogenerado, foto opcional, estados, filtros, exportación PDF/XLSX. |
| **Captura rápida** | Alta veloz con formulario mínimo y re-alta inmediata. |
| **Scanner** | Búsqueda por código (`items.scan`) pensado para scanner USB tipo teclado (normaliza trim + mayúsculas). |
| **Etiquetas** | Ticket imprimible `50×30mm` con QR del `codigo`. |
| **Categorías** | Catálogo con nombre único. |
| **Ubicaciones** | Catálogo con nombre único y descripción. |
| **Reportes** | Inventario y movimientos (web, PDF, XLSX) con filtros y trazabilidad. |
| **Usuarios** | CRUD completo con asignación de roles (no se puede borrar el último Admin ni a quien tiene ventas). |
| **Roles/permisos** | Matriz fija de 21 permisos en 4 grupos operativos + 2 roles legacy. |
| **Mini POS** | Carrito por sesión, escáner para agregar, venta atómica con `lockForUpdate`. |
| **Ventas** | Folio `VTA-XXXXXX`, detalle, actor (`user_id`) con FK RESTRICT. |
| **Tickets** | Impresión/reimpresión web de comprobante térmico 80mm/58mm con precios históricos. |

## Lo que NO existe (por diseño)

- ecommerce / catálogo público
- CFDI / facturación / IVA
- cuentas por cobrar
- devoluciones
- promociones / descuentos
- cierre de caja
- impresión directa ESC/POS (la impresión es vía navegador `window.print()`)
- borrado operacional de Items (el flujo es `BAJA`, no eliminar)

---

## Identidad y ciclo de vida

- Cada `Item` recibe un código único `ITM-XXXXXX` desde la secuencia PostgreSQL `items_codigo_seq_generator`. Los huecos numéricos son normales y no se reutilizan.
- Estados: `DISPONIBLE`, `RESERVADO`, `REPARACION`, `VENDIDO`, `BAJA`.
- `VENDIDO` y `BAJA` son **terminales** (las transiciones se validan en servidor con row lock).
- `SoftDeletes` existe como **salvaguarda técnica/legacy**: NO hay papelería operativa ni rutas de restaurar/borrar definitivo.
- Cada operación relevante genera un `Movimiento` con estado anterior/posterior, ubicación, usuario actor y evidencia opcional.

---

## Roles y permisos

**Canon (21 permisos):**
`dashboard.ver` · `items.ver` · `items.crear` · `items.editar` · `items.cambiar_estado` · `items.mover` · `reportes.ver` · `categorias.ver|crear|editar|eliminar` · `ubicaciones.ver|crear|editar|eliminar` · `usuarios.ver|crear|editar|eliminar` · `ventas.ver` · `ventas.crear`

| Rol | Permisos |
|---|---|
| **Admin** | Los 21. |
| **Almacen** | Dashboard, items (ver/crear/editar/cambiar_estado/mover), reportes, categorías y ubicaciones (sin eliminar). Sin ventas ni usuarios. |
| **Auditor** | Solo lectura + reportes + histórico de ventas. Sin escritura, sin POS. |
| **Ventas** | `dashboard.ver`, `items.ver`, `ventas.ver`, `ventas.crear`. |
| **Operador / Consulta** | Legacy, 0 permisos. |

---

## Instalación (desarrollo)

```bash
git clone <repo> && cd inventario-desechos
composer install
npm install

cp .env.example .env
php artisan key:generate
# configurar DB (PostgreSQL) en .env

php artisan migrate
php artisan db:seed --class=RolesAndAdminSeeder --class=CatalogosBaseSeeder
php artisan storage:link

npm run dev        # Tailwind/Vite
php artisan serve  # o tu servidor web
```

> En el seeder el **Admin inicial** se crea ÚNICAMENTE si definís `SEED_ADMIN_EMAIL` y `SEED_ADMIN_PASSWORD` (juntos, min. 12 caracteres) en el entorno. Nunca hay contraseña por defecto en el repositorio.

## Testing

```bash
cp .env.testing.example .env.testing   # DB de pruebas aislada
php artisan test                       # suite completa (194 tests / 619 assertions)
php artisan migrate:fresh --seed --env=testing   # instala limpia de pruebas
```

La suite cubre matriz de roles, atomicidad del POS, dinero exacto en centavos, ticket con precios históricos, FK/UNIQUE de PostgreSQL y trazabilidad.

---

## Producción / deploy

Ver [docs/DEPLOY.md](docs/DEPLOY.md) (requisitos, variables, migraciones, seed seguro, build, web server, HTTPS, cachés, rollback) y [docs/BACKUP_RESTORE.md](docs/BACKUP_RESTORE.md) (respaldo/restauración de PostgreSQL + storage) y [docs/CHECKLIST_PRE_DEPLOY.md](docs/CHECKLIST_PRE_DEPLOY.md).

Resumen de comandos clave:

```bash
php artisan migrate --force                # nunca migrate:fresh en producción
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan optimize
```

---

## Seguridad básica

- Todas las rutas funcionales exigen `auth` + permiso por middleware; las únicas públicas son login/recuperación de contraseña y el healthcheck `/up`.
- CSRF activo en todos los formularios; login con rate limiting (RateLimiter, 5 intentos); verificación de email firmada.
- Cabeceras mínimas en todas las respuestas (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`); CSP recomendada vía web server.
- Dinero sin float: persistencia **PostgreSQL `numeric(12,2)`** (`items.precio`, `ventas.total`, `venta_detalles.precio`); en PHP se **calcula en centavos enteros** (decimal de BD → centavos → suma exacta → decimal string) y se presenta con helper sin float; el ticket usa el precio histórico de `VentaDetalle`.
- No se suben `.env` (`.gitignore`); backups incluyen PostgreSQL + `storage/app` + configuración.

---

## Notas técnicas

- Secuencias PostgreSQL: `items_codigo_seq_generator`, `ventas_folio_seq_generator` (nunca `MAX()+1`; los gaps son normales).
- FKs críticas: `movimientos.item_id → items (RESTRICT)`, `venta_detalles.item_id → items (RESTRICT + UNIQUE)`, `ventas.user_id → users (RESTRICT + NOT NULL)`.
- Listados principales paginados (15/25) y con eager loading (sin N+1).

---

## Autor

netrok