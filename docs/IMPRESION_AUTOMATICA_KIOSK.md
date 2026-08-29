# Impresión automática de tickets (kiosk mode)

La aplicación imprime tickets térmicos **a través del navegador** usando
`window.print()`. No usa QZ, PrintNode ni protocolo ESC/POS directo.

Existen dos modos:

- **Manual**: el usuario abre el ticket y pulsa "Imprimir".
- **Automático (kiosk)**: tras una venta, el ticket se imprime solo.

---

## Configuración

En **Configuración** (`/configuracion`):

- `ticket_ancho` → `58` o `80` mm (únicos valores permitidos).
- `ticket_autoprint` → habilita/deshabilita la impresión automática al abrir el
  ticket desde el flujo de venta (`ventas.ticket?autoprint=1`).

## Precedencia (control estricto del autoprint)

El `window.print()` automático **sólo** se inyecta cuando se cumplen las DOS
condiciones:

1. La URL del ticket lleva `?autoprint=1`, **y**
2. `configuracion.ticket_autoprint` está activado.

Y además, **solo el flujo POST-checkout** genera esa URL con `autoprint=1`
(`PosController::checkout` redirige a `ventas.ticket?...autoprint=1` cuando el
autoprint está habilitado).

Las consecuencias por diseño:

- **Ver un ticket histórico** (desde "Ver ticket" con `?width=58|80`) **nunca**
  autoprinta, aunque la configuración esté activada.
- **Reimprimir** un ticket existente tampoco autoprinta de forma inesperada.
- El parámetro `autoprint` no puede provocar impresión sola: si la configuración
  está apagada, `?autoprint=1` no hace nada.
- Imprimir **no muta la venta** (la reimpresión es de solo lectura), por lo que
  un `window.print()` adicional no altera datos.

### Refrescar la página del ticket

Tras el checkout, el navegador aterriza en `ventas.ticket?...autoprint=1`. Si el
usuario pulsa **F5**, la petición GET se re-ejecuta y el autoprint volverá a
dispararse una vez más. Esto es **solo impresión** (no duplica la venta: el
checkout usa PRG y el carrito ya se limpió), por lo que se acepta como limitación
menor de UX en un cajero kiosk.

---

## Kiosk con Chromium

Para impresión sin intervención (p. ej. en un punto de venta):

```bash
chromium \
  --kiosk \
  --kiosk-printing \
  --disable-pinch \
  --noerrdialogs \
  --disable-infobars \
  --no-default-browser-check \
  http://localhost:8000/login
```

Flags clave:

- `--kiosk`: pantalla completa, sin barra de direcciones.
- `--kiosk-printing`: **no muestra el diálogo de impresión**, imprime directo a la
  impresora por defecto del sistema (`window.print()` retorna de inmediato).

> El `--kiosk-printing` es lo que convierte `window.print()` en impresión
> silenciosa. Sin esa flag, el ticket abrirá el diálogo del sistema.

## Notas

- La vista del ticket es `@media print` con `@page { size: 58mm auto; }` según el
  ancho configurado, para que el navegador respete el tamaño del papel térmico.
- La reimpresión de tickets existentes es de **solo lectura**: no altera ventas
  ni genera movimientos.
