# Módulo Clientes

Catálogo de personas y empresas vinculadas a las ventas del Mini POS.

---

## Modelo y ciclo de vida

- **Código** autogenerado `CLI-XXXXXX` (secuencia PostgreSQL, no se reutiliza).
- Campos: `nombre`, `tipo` (`PERSONA` / `EMPRESA`), `rfc`, `email`, `telefono`,
  `direccion`, `notas` y `activo` (booleano).
- Datos sensibles (`rfc`, `email`, `telefono`) se **normalizan** al guardar
  (trim + `rfc` mayúsculas, `email` minúsculas).
- Ciclo de vida **ACTIVO / INACTIVO**: no hay borrado físico ni endpoint de
  `destroy`. Un cliente inactivo no puede seleccionarse para nuevas ventas,
  pero sus ventas históricas se conservan íntegras.

## Permisos

| Permiso | Alcance |
|---|---|
| `clientes.ver` | Consultar catálogo y ficha/historial |
| `clientes.crear` | Alta (incluye alta rápida desde el POS) |
| `clientes.editar` | Edición |
| `clientes.desactivar` | Activar / desactivar |

## En el POS

- El checklist de venta **exige un cliente ACTIVO** seleccionado (o alta rápida).
- Del cliente se guarda un **snapshot histórico**
  (`cliente_codigo/nombre/rfc/telefono/email/tipo`) en la venta al momento del
  checkout. Editar o desactivar posteriormente el cliente **no** altera los
  snapshots ya registrados.
- Las ventas legacy previas (sin cliente) se muestran como
  "Cliente no registrado (venta histórica)".

## Rutas

- `clientes.rapida` es **POST**: crea (o reutiliza) un cliente mínimo
  (`tipo` + `nombre`) y deja la selección activa en el POS.
- `pos.cliente` / `pos.cliente.limpiar` gestionan la selección de sesión.
