<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $documento->folio }} — {{ $documento->tipo }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', system-ui, Roboto, sans-serif; background: #f3f4f6; color: #111827; }

        @page { size: 80mm auto; margin: 3mm; }

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

        .ticket {
            width: 80mm;
            background: #ffffff;
            border: 1px solid #d1d5db;
            padding: 7px 9px;
            font-size: 11px;
            line-height: 1.35;
        }

        .ticket h1 {
            text-align: center;
            font-size: 13px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .datos { border-top: 1px dashed #9ca3af; border-bottom: 1px dashed #9ca3af; padding: 4px 0; margin-bottom: 6px; }
        .datos-wrap { display: flex; justify-content: space-between; gap: 6px; }
        .datos-wrap .k { font-weight: 700; }
        .datos-wrap .v { text-align: right; }

        .item { padding: 5px 0; border-bottom: 1px dotted #d1d5db; }
        .item .top { display: flex; justify-content: space-between; gap: 6px; align-items: baseline; }
        .item .codigo { font-weight: 700; }
        .item .precio { font-weight: 700; white-space: nowrap; }
        .item .desc { color: #374151; }

        .totales { display: flex; justify-content: space-between; align-items: center; padding: 7px 0 4px; border-bottom: 1px dashed #9ca3af; }
        .totales .label { font-weight: 700; letter-spacing: 0.5px; }
        .totales .monto { font-size: 17px; font-weight: 800; }

        .motivo { margin-top: 6px; }
        .motivo .k { font-weight: 700; }

        .pie { text-align: center; margin-top: 8px; color: #4b5563; letter-spacing: 0.3px; }
        .pie .folio { font-weight: 700; color: #111827; }

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
            <h1>{{ $documento->esCancelacion() ? 'Cancelación de venta' : 'Devolución de equipos' }}</h1>

            <div class="datos">
                <div class="datos-wrap"><span class="k">Folio</span><span class="v">{{ $documento->folio }}</span></div>
                <div class="datos-wrap"><span class="k">Tipo</span><span class="v">{{ $documento->tipo }}</span></div>
                <div class="datos-wrap"><span class="k">Venta</span><span class="v">{{ $documento->venta->folio }}</span></div>
                <div class="datos-wrap"><span class="k">Fecha</span><span class="v">{{ $documento->created_at->format('Y-m-d H:i') }}</span></div>
                <div class="datos-wrap"><span class="k">Usuario</span><span class="v">{{ $documento->user?->name ?? '—' }}</span></div>
                @if($documento->forma_reembolso)
                    <div class="datos-wrap"><span class="k">Reembolso</span><span class="v">{{ $documento->forma_reembolso }}</span></div>
                @endif
                <div class="datos-wrap"><span class="k">Estado venta</span><span class="v">{{ $documento->venta->estado }}</span></div>
            </div>

            @foreach($documento->detalles as $detalle)
                <div class="item">
                    <div class="top">
                        <span class="codigo">{{ $detalle->item?->codigo ?? 'SIN EQUIPO' }}</span>
                        <span class="precio">{{ number_format((float) $detalle->importe, 2) }}</span>
                    </div>
                    @if($detalle->item)
                        <div class="desc">
                            {{ collect([$detalle->item->marca, $detalle->item->modelo])->filter()->implode(' · ') ?: 'Sin descripción' }}
                        </div>
                    @endif
                </div>
            @endforeach

            <div class="totales">
                <span class="label">Total</span>
                <span class="monto">{{ number_format((float) $documento->total, 2) }}</span>
            </div>

            <div class="motivo">
                <div class="k">Motivo</div>
                <div>{{ $documento->motivo }}</div>
            </div>

            <div class="pie">
                <span class="folio">{{ $documento->folio }}</span><br>
                Inventario Desechos — postventa
            </div>
        </div>
    </div>
</body>
</html>