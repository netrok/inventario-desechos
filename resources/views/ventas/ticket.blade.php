<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ticket {{ $venta->folio }}</title>
    @php
        use App\Support\TicketTexto;
        $cols = TicketTexto::columnas($width);
    @endphp
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', system-ui, Roboto, sans-serif; background: #f3f4f6; color: #111827; }

        /* Solo permite 58 o 80 (validado en el controlador): nunca CSS arbitrario. */
        @page { size: {{ $width }}mm auto; margin: 3mm; }

        .no-print { background: #111827; }
        .no-print a, .no-print button {
            display: inline-flex; align-items: center;
            margin: 12px 8px 0 12px; padding: 8px 14px;
            border-radius: 8px; font-size: 14px; font-weight: 600;
            color: #f9fafb; background: #374151; text-decoration: none; border: none;
            cursor: pointer;
        }
        .no-print button.print { background: #059669; }
        .no-print button.print:hover { background: #047857; }

        .stage { display: flex; justify-content: center; padding: 20px 8px; }

        /*
         * IMPORTANTE (bug 08-sep-2026): el contenido del ticket es texto
         * monoespaciado real (líneas ya rellenadas con espacios ASCII por
         * App\Support\TicketTexto), NO layout de flexbox. Esto es a propósito:
         * algunas impresoras/drivers térmicos aplanan el HTML a texto plano al
         * imprimir e ignoran por completo el CSS de posición, así que el
         * espaciado tiene que existir en el propio texto para verse bien en
         * cualquiera de los dos casos (impresión con CSS o texto plano).
         * No reemplazar estas líneas por <div> con flexbox / justify-content.
         */
        /*
         * El ancho de la caja se define en "ch" (ancho real de un carácter
         * en la fuente que el navegador esté usando de verdad), NO en mm ni
         * en px calculados a mano. Así, si en la máquina de Ernesto "Courier
         * New" se renderiza un poco más ancha o más angosta que en el
         * entorno donde se probó esto, la caja se ajusta sola y el texto
         * (que ya viene relleno a $cols caracteres exactos) nunca se
         * envuelve a una segunda línea. Bug 08-sep-2026: antes el ancho
         * estaba fijo en milímetros y el tamaño de letra se adivinaba en
         * píxeles, y con la fuente real de Windows los renglones (fecha,
         * cliente, separadores) se cortaban a la mitad.
         */
        .ticket {
            width: fit-content;
            background: #ffffff;
            border: 1px solid #d1d5db;
            padding: {{ $width === 58 ? '5px 6px' : '7px 9px' }};
        }
        .ticket pre {
            font-family: 'Courier New', Courier, monospace;
            font-size: {{ $width === 58 ? '10px' : '12px' }};
            line-height: 1.35;
            width: {{ $cols }}ch;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .ticket .negrita { font-weight: 700; }
        .ticket .grande { font-weight: 800; }
        .ticket .tenue { color: #4b5563; }
        .ticket .cambio { color: #b45309; }

        @media print {
            body { background: #ffffff; }
            .no-print { display: none !important; }
            .stage { padding: 0; }
            .ticket { border: none; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button type="button" class="print" onclick="window.print()">Imprimir</button>
        <button type="button" onclick="cerrarTicket()">Cerrar</button>
        <a href="{{ route('ventas.show', $venta) }}">← Volver al detalle</a>
        @if($errors->any())
            <span style="color:#fca5a5">{{ $errors->first() }}</span>
        @endif
    </div>

    <div class="stage">
        <div class="ticket">
            <pre>
{{ TicketTexto::separador($cols, '=') }}
<span class="negrita">{{ TicketTexto::centrado($configuracion['empresa_nombre'] ?: config('app.name', 'Inventario ReUse'), $cols) }}</span>
@if($configuracion['empresa_rfc'])
<span class="tenue">{{ TicketTexto::centrado('RFC '.$configuracion['empresa_rfc'], $cols) }}</span>
@endif
@if($configuracion['empresa_direccion'])
@foreach(TicketTexto::envolver($configuracion['empresa_direccion'], $cols) as $renglon)
<span class="tenue">{{ TicketTexto::centrado($renglon, $cols) }}</span>
@endforeach
@endif
@if($configuracion['empresa_telefono'] || $configuracion['empresa_email'])
<span class="tenue">{{ TicketTexto::centrado(collect([$configuracion['empresa_telefono'], $configuracion['empresa_email']])->filter()->implode(' - '), $cols) }}</span>
@endif
{{ TicketTexto::separador($cols, '=') }}
<span class="negrita">{{ TicketTexto::centrado('COMPROBANTE DE VENTA', $cols) }}</span>
{{ TicketTexto::separador($cols) }}
@foreach(TicketTexto::linea('Folio', $venta->folio, $cols) as $renglon)
{{ $renglon }}
@endforeach
@foreach(TicketTexto::linea('Fecha', $venta->created_at->format('Y-m-d H:i'), $cols) as $renglon)
{{ $renglon }}
@endforeach
@foreach(TicketTexto::linea('Vendedor', $venta->user?->name ?? '-', $cols) as $renglon)
{{ $renglon }}
@endforeach
@foreach(TicketTexto::linea('Forma de pago', $venta->forma_pago, $cols) as $renglon)
{{ $renglon }}
@endforeach
@if($venta->cliente_historico)
@php $ch = $venta->cliente_historico; @endphp
@foreach(TicketTexto::linea('Cliente', $ch['nombre'], $cols) as $renglon)
<span class="negrita">{{ $renglon }}</span>
@endforeach
@if($ch['rfc'])
@foreach(TicketTexto::linea('RFC', $ch['rfc'], $cols) as $renglon)
{{ $renglon }}
@endforeach
@endif
@if($ch['telefono'])
@foreach(TicketTexto::linea('Telefono', $ch['telefono'], $cols) as $renglon)
{{ $renglon }}
@endforeach
@endif
@else
@foreach(TicketTexto::linea('Cliente', 'No registrado', $cols) as $renglon)
{{ $renglon }}
@endforeach
@endif
{{ TicketTexto::separador($cols) }}
@foreach($venta->detalles as $detalle)
@foreach(TicketTexto::linea($loop->iteration.') '.($detalle->item?->codigo ?? 'SIN EQUIPO'), $preciosFormateados[$detalle->id] ?? $detalle->precio, $cols) as $renglon)
<span class="negrita">{{ $renglon }}</span>
@endforeach
@if($detalle->item)
@php
$desc = collect([$detalle->item->marca, $detalle->item->modelo])->filter()->implode(' - ') ?: 'Sin descripcion';
if ($detalle->item->categoria?->nombre) { $desc .= ' ('.$detalle->item->categoria->nombre.')'; }
@endphp
@foreach(TicketTexto::envolver($desc, $cols) as $renglon)
<span class="tenue">{{ $renglon }}</span>
@endforeach
@if($detalle->item->serie)
<span class="tenue">{{ TicketTexto::envolver('Serie: '.$detalle->item->serie, $cols)[0] }}</span>
@endif
@endif
{{ TicketTexto::separador($cols, '.') }}
@endforeach
{{ TicketTexto::separador($cols, '=') }}
@foreach(TicketTexto::linea('TOTAL', $totalFormateado, $cols) as $renglon)
<span class="grande">{{ $renglon }}</span>
@endforeach
{{ TicketTexto::separador($cols, '=') }}
@if($venta->pagos->isNotEmpty())
<span class="negrita">PAGOS</span>
@foreach($venta->pagos as $pago)
@foreach(TicketTexto::linea($pago->metodo, \App\Support\Money::formatear((string) $pago->monto_aplicado), $cols) as $renglon)
{{ $renglon }}
@endforeach
@if($pago->efectivo_recibido !== null && \App\Support\Money::aCentavos((string) $pago->efectivo_recibido) > 0)
@foreach(TicketTexto::linea('  Recibido', \App\Support\Money::formatear((string) $pago->efectivo_recibido), $cols) as $renglon)
{{ $renglon }}
@endforeach
@endif
@if($pago->cambio_entregado !== null && \App\Support\Money::aCentavos((string) $pago->cambio_entregado) > 0)
@foreach(TicketTexto::linea('  Cambio', \App\Support\Money::formatear((string) $pago->cambio_entregado), $cols) as $renglon)
<span class="cambio">{{ $renglon }}</span>
@endforeach
@endif
@endforeach
{{ TicketTexto::separador($cols) }}
@endif
@if($venta->cuentaPorCobrar)
@php $cxc = $venta->cuentaPorCobrar; @endphp
<span class="negrita">CREDITO (CxC)</span>
@foreach(TicketTexto::linea('Folio', $cxc->folio, $cols) as $renglon)
{{ $renglon }}
@endforeach
@foreach(TicketTexto::linea('Financiado', \App\Support\Money::formatear(\App\Support\Money::aPrecio($cxc->importe_original_centavos)), $cols) as $renglon)
{{ $renglon }}
@endforeach
@foreach(TicketTexto::linea('Saldo', \App\Support\Money::formatear(\App\Support\Money::aPrecio($cxc->saldo_centavos)), $cols) as $renglon)
{{ $renglon }}
@endforeach
@foreach(TicketTexto::linea('Vence', (string) $cxc->fecha_vencimiento?->format('Y-m-d'), $cols) as $renglon)
{{ $renglon }}
@endforeach
@foreach(TicketTexto::linea('Plazo', $cxc->dias_credito_aplicados.' dia(s)', $cols) as $renglon)
{{ $renglon }}
@endforeach
{{ TicketTexto::separador($cols) }}
@endif
@if($venta->notas)
<span class="negrita">Notas</span>
@foreach(TicketTexto::envolver($venta->notas, $cols) as $renglon)
{{ $renglon }}
@endforeach
@endif

{{ TicketTexto::separador($cols, '=') }}
<span class="negrita">{{ TicketTexto::centrado($venta->folio, $cols) }}</span>
<span>{{ TicketTexto::centrado('Gracias por su compra', $cols) }}</span>
@if($configuracion['ticket_pie'])
@foreach(explode("\n", $configuracion['ticket_pie']) as $renglonPie)
@foreach(TicketTexto::envolver($renglonPie, $cols) as $renglon)
<span class="tenue">{{ TicketTexto::centrado($renglon, $cols) }}</span>
@endforeach
@endforeach
@endif
{{ TicketTexto::separador($cols, '=') }}
</pre>
        </div>
    </div>

    <script>
        function cerrarTicket() {
            // Si el ticket se abrió desde otra ventana/pestaña de la app,
            // cerramos solamente el ticket.
            if (window.opener && !window.opener.closed) {
                window.close();
                return;
            }

            // Si estamos en la pestaña principal, nunca cerramos la app:
            // regresamos al detalle de la venta.
            window.location.href = @json(route('ventas.show', $venta));
        }
    </script>

    @if($autoprint)
        <script>
            window.addEventListener('load', function () {
                setTimeout(function () {
                    window.print();
                }, 250);
            });
        </script>
    @endif
</body>
</html>
