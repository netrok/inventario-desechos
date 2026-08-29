# Entornos: Demo y Test (separación de datos)

Este documento describe las reglas para mantener **separados** los datos de una
instancia de demostración/uso manual de los datos destructibles de las pruebas
automatizadas.

---

## Regla general

- **Demo** = datos de demostración / uso manual. Se modifican a voluntad.
- **Testing** = base de datos destruida en cada ejecución de pruebas.

Los datos de uno **nunca** deben usarse en el otro.

---

## Variables de entorno

| Archivo (no versionado) | APP_ENV | DB_DATABASE | Uso |
|---|---|---|---|
| `.env` | `local` | `inventario_desechos` | Desarrollo local |
| `.env.demo` | `demo` | `inventario_desechos_demo` | Demostración / uso manual |
| `.env.testing` | `testing` | `inventario_desechos_test` | Pruebas automatizadas |

Los archivos `.env`, `.env.demo` y `.env.testing` **no** se suben al repositorio.
Solo se versionan sus plantillas: `.env.example` y `.env.testing.example`.

### `DB_PASSWORD`

La base **demo** se crea **sin contraseña** (`DB_PASSWORD` vacío). Mantenerlo así
en `.env.demo` para que coincida con la config local de PostgreSQL fácil de usar
al levantar una demo.

---

## Diseño por separado

- La **demo** es manipulable a mano: se puede `migrate`, `db:seed`, abrir paneles
  y probar el ciclo completo del negocio sin riesgo de romper las pruebas.
- El **testing** usa `RefreshDatabase` en cada test: la base se migra y se limpia
  antes de cada ejecución. Cualquier dato sembrado en testing debe regenerarse
  en cada prueba.

## Flujos

### Demo / uso manual

```bash
cp .env.demo .env          # solo si se desea ejecutar la demo
php artisan migrate:fresh --seed
php artisan serve
```

### Pruebas

```bash
php artisan test --env=testing
```

> `--env=testing` es obligatorio para que las pruebas apunten a
> `inventario_desechos_test` y no toquen los datos de desarrollo o demo.
