<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $documento->folio }} - {{ $documento->tipo }}</title>
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
         * IMPORTANTE (bug 08-sep-2026, mismo caso que ventas.ticket): el
         * contenido es texto monoespaciado real (rellenado con espacios
         * ASCII por App\Support\TicketTexto), NO layout de flexbox — el
         * driver de la impresora térmica de Ernesto aplana el HTML a texto
         * plano e ignora el CSS de posición. No reemplazar por <div> con
         * flexbox / justify-content.
         *
         * El ancho de la caja se define en "ch" (ancho real de un carácter
         * en la fuente que el navegador use de verdad), no en mm/px fijos,
         * para que nunca se envuelva a una segunda línea sin importar cómo
         * se renderice "Courier New" en la máquina de cada quien.
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
        <button type="button" onclick="window.close()">Cerrar</button>
        <a href="{{ route('postventa.show', $documento) }}">← Volver al detalle</a>
    </div>

    <div class="stage">
        <div class="ticket">
            <pre>
{{ TicketTexto::separador($cols, '=') }}
<span class="negrita">{{ TicketTexto::centrado($documento->esCancelacion() ? 'CANCELACION DE VENTA' : 'DEVOLUCION DE EQUIPOS', $cols) }}</span>
{{ TicketTexto::separador($cols, '=') }}
@foreach(TicketTexto::linea('Folio', $documento->folio, $cols) as $renglon)
{{ $renglon }}
@endforeach
@foreach(TicketTexto::linea('Tipo', $documento->tipo, $cols) as $renglon)
{{ $renglon }}
@endforeach
@foreach(TicketTexto::linea('Venta', $documento->venta->folio, $cols) as $renglon)
{{ $renglon }}
@endforeach
@foreach(TicketTexto::linea('Fecha', $documento->created_at->format('Y-m-d H:i'), $cols) as $renglon)
{{ $renglon }}
@endforeach
@foreach(TicketTexto::linea('Usuario', $documento->user?->name ?? '-', $cols) as $renglon)
{{ $renglon }}
@endforeach
@if($documento->forma_reembolso)
@foreach(TicketTexto::linea('Reembolso', $documento->forma_reembolso, $cols) as $renglon)
{{ $renglon }}
@endforeach
@endif
@foreach(TicketTexto::linea('Estado venta', $documento->venta->estado, $cols) as $renglon)
{{ $renglon }}
@endforeach
@if($documento->venta->cliente_historico)
@php $ch = $documento->venta->cliente_historico; @endphp
@foreach(TicketTexto::linea('Cliente', $ch['nombre'].' ('.($ch['rfc'] ?: $ch['codigo']).')', $cols) as $renglon)
{{ $renglon }}
@endforeach
@endif
{{ TicketTexto::separador($cols) }}
@foreach($documento->detalles as $detalle)
@foreach(TicketTexto::linea($loop->iteration.') '.($detalle->item?->codigo ?? 'SIN EQUIPO'), number_format((float) $detalle->importe, 2), $cols) as $renglon)
<span class="negrita">{{ $renglon }}</span>
@endforeach
@if($detalle->item)
@php
$desc = collect([$detalle->item->marca, $detalle->item->modelo])->filter()->implode(' - ') ?: 'Sin descripcion';
@endphp
@foreach(TicketTexto::envolver($desc, $cols) as $renglon)
<span class="tenue">{{ $renglon }}</span>
@endforeach
@endif
{{ TicketTexto::separador($cols, '.') }}
@endforeach
{{ TicketTexto::separador($cols, '=') }}
@foreach(TicketTexto::linea('TOTAL', number_format((float) $documento->total, 2), $cols) as $renglon)
<span class="grande">{{ $renglon }}</span>
@endforeach
{{ TicketTexto::separador($cols, '=') }}
@php
$reembolsoMonetarioCentavos = $documento->reembolsos->sum(
    fn ($r) => \App\Support\Money::aCentavos((string) $r->monto)
);
$deudaCentavos = $documento->movimientoCxCDeuda
    ? (int) $documento->movimientoCxCDeuda->monto_centavos
    : 0;
@endphp
@if($documento->movimientoCxCDeuda || $documento->reembolsos->isNotEmpty())
@foreach(TicketTexto::linea('Deuda CxC', number_format($deudaCentavos / 100, 2), $cols) as $renglon)
{{ $renglon }}
@endforeach
@foreach(TicketTexto::linea('Reembolso', number_format($reembolsoMonetarioCentavos / 100, 2), $cols) as $renglon)
{{ $renglon }}
@endforeach
@foreach($documento->reembolsos as $reembolso)
@php
$etiqueta = $reembolso->metodo.' '.($reembolso->esCxC() ? '(CxC)' : ($reembolso->pagoVenta ? '(PAGO)' : '(LEGACY)'));
@endphp
@foreach(TicketTexto::linea($etiqueta, number_format((float) $reembolso->monto, 2), $cols) as $renglon)
{{ $renglon }}
@endforeach
@endforeach
{{ TicketTexto::separador($cols) }}
@endif
<span class="negrita">Motivo</span>
@foreach(TicketTexto::envolver($documento->motivo, $cols) as $renglon)
{{ $renglon }}
@endforeach
{{ TicketTexto::separador($cols, '=') }}
<span class="negrita">{{ TicketTexto::centrado($documento->folio, $cols) }}</span>
<span>{{ TicketTexto::centrado('Inventario ReUse - postventa', $cols) }}</span>
{{ TicketTexto::separador($cols, '=') }}
</pre>
        </div>
    </div>
</body>
</html>
