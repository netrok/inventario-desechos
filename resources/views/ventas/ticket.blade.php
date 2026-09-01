<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ticket {{ $venta->folio }}</title>
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

        .ticket {
            width: {{ $width }}mm;
            background: #ffffff;
            border: 1px solid #d1d5db;
            padding: {{ $width === 58 ? '5px 6px' : '7px 9px' }};
            font-size: {{ $width === 58 ? '9px' : '11px' }};
            line-height: 1.35;
        }

        .ticket .empresa {
            text-align: center;
            font-size: {{ $width === 58 ? '11px' : '13px' }};
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        .ticket .empresa-extra {
            text-align: center;
            font-size: {{ $width === 58 ? '8px' : '10px' }};
            color: #4b5563;
        }

        .datos { border-top: 1px dashed #9ca3af; border-bottom: 1px dashed #9ca3af; padding: 4px 0; margin-bottom: 6px; }
        .datos-wrap { display: flex; justify-content: space-between; gap: 6px; }
        .datos-wrap .k { font-weight: 700; }
        .datos-wrap .v { text-align: right; }
        .datos .cliente-nombre { font-weight: 700; }

        .item { padding: 5px 0; border-bottom: 1px dotted #d1d5db; }
        .item .top { display: flex; justify-content: space-between; gap: 6px; align-items: baseline; }
        .item .codigo { font-weight: 700; }
        .item .precio { font-weight: 700; white-space: nowrap; }
        .item .desc { color: #374151; }
        .item .serie { color: #4b5563; }

        .totales { display: flex; justify-content: space-between; align-items: center; padding: 7px 0 4px; border-bottom: 1px dashed #9ca3af; }
        .totales .label { font-weight: 700; letter-spacing: 0.5px; }
        .totales .monto { font-size: {{ $width === 58 ? '14px' : '17px' }}; font-weight: 800; }

        .pagos { border-bottom: 1px dashed #9ca3af; padding: 4px 0; }
        .pagos .titulo { font-weight: 700; letter-spacing: 0.5px; }
        .pagos .row { display: flex; justify-content: space-between; gap: 6px; }
        .pagos .row .k { color: #374151; }
        .pagos .row .v { font-weight: 700; }
        .pagos .cambio { color: #b45309; }

        .notas { margin-top: 6px; }
        .notas .k { font-weight: 700; }

        .pie { text-align: center; margin-top: 8px; color: #4b5563; letter-spacing: 0.3px; white-space: pre-line; }
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
        <button type="button" onclick="cerrarTicket()">Cerrar</button>
        <a href="{{ route('ventas.show', $venta) }}">← Volver al detalle</a>
        @if($errors->any())
            <span style="color:#fca5a5">{{ $errors->first() }}</span>
        @endif
    </div>

    <div class="stage">
        <div class="ticket">
            <div class="empresa">{{ $configuracion['empresa_nombre'] ?: config('app.name', 'Inventario ReUse') }}</div>
            @if($configuracion['empresa_rfc'])
                <div class="empresa-extra">RFC {{ $configuracion['empresa_rfc'] }}</div>
            @endif
            @if($configuracion['empresa_direccion'])
                <div class="empresa-extra">{{ $configuracion['empresa_direccion'] }}</div>
            @endif
            @if($configuracion['empresa_telefono'] || $configuracion['empresa_email'])
                <div class="empresa-extra">
                    {{ $configuracion['empresa_telefono'] }}{{ $configuracion['empresa_telefono'] && $configuracion['empresa_email'] ? ' · ' : '' }}{{ $configuracion['empresa_email'] }}
                </div>
            @endif

            <h1 style="text-align:center; font-size:{{ $width === 58 ? '11px' : '13px' }}; letter-spacing:0.5px; text-transform:uppercase; margin:4px 0 6px;">Comprobante de venta</h1>

            <div class="datos">
                <div class="datos-wrap"><span class="k">Folio</span><span class="v">{{ $venta->folio }}</span></div>
                <div class="datos-wrap"><span class="k">Fecha</span><span class="v">{{ $venta->created_at->format('Y-m-d H:i') }}</span></div>
                <div class="datos-wrap"><span class="k">Vendedor</span><span class="v">{{ $venta->user?->name ?? '—' }}</span></div>
                <div class="datos-wrap"><span class="k">Forma de pago</span><span class="v">{{ $venta->forma_pago }}</span></div>

                @if($venta->cliente_historico)
                    @php $ch = $venta->cliente_historico; @endphp
                    <div class="datos-wrap"><span class="k">Cliente</span><span class="v cliente-nombre">{{ $ch['nombre'] }}</span></div>
                    @if($ch['rfc']) <div class="datos-wrap"><span class="k">RFC</span><span class="v">{{ $ch['rfc'] }}</span></div> @endif
                    @if($ch['telefono']) <div class="datos-wrap"><span class="k">Teléfono</span><span class="v">{{ $ch['telefono'] }}</span></div> @endif
                @else
                    <div class="datos-wrap"><span class="k">Cliente</span><span class="v">No registrado (venta histórica)</span></div>
                @endif
            </div>

            @foreach($venta->detalles as $detalle)
                <div class="item">
                    <div class="top">
                        <span class="codigo">{{ $detalle->item?->codigo ?? 'SIN EQUIPO' }}</span>
                        <span class="precio">{{ $preciosFormateados[$detalle->id] ?? $detalle->precio }}</span>
                    </div>
                    @if($detalle->item)
                        <div class="desc">
                            {{ collect([$detalle->item->marca, $detalle->item->modelo])->filter()->implode(' · ') ?: 'Sin descripción' }}
                            @if($detalle->item->categoria?->nombre)
                                ({{ $detalle->item->categoria->nombre }})
                            @endif
                        </div>
                        @if($detalle->item->serie)
                            <div class="serie">Serie: {{ $detalle->item->serie }}</div>
                        @endif
                    @endif
                </div>
            @endforeach

            <div class="totales">
                <span class="label">Total</span>
                <span class="monto">{{ $totalFormateado }}</span>
            </div>

            @if($venta->pagos->isNotEmpty())
                <div class="pagos">
                    <div class="titulo">Pagos</div>
                    @foreach($venta->pagos as $pago)
                        <div class="row">
                            <span class="k">{{ $pago->metodo }}</span>
                            <span class="v">{{ \App\Support\Money::formatear((string) $pago->monto_aplicado) }}</span>
                        </div>
                        @if($pago->efectivo_recibido !== null && \App\Support\Money::aCentavos((string) $pago->efectivo_recibido) > 0)
                            <div class="row">
                                <span class="k">&nbsp;&nbsp;Recibido</span>
                                <span class="v">{{ \App\Support\Money::formatear((string) $pago->efectivo_recibido) }}</span>
                            </div>
                        @endif
                        @if($pago->cambio_entregado !== null && \App\Support\Money::aCentavos((string) $pago->cambio_entregado) > 0)
                            <div class="row">
                                <span class="k">&nbsp;&nbsp;Cambio</span>
                                <span class="v cambio">{{ \App\Support\Money::formatear((string) $pago->cambio_entregado) }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif

            @if($venta->cuentaPorCobrar)
                @php $cxc = $venta->cuentaPorCobrar; @endphp
                <div class="pagos">
                    <div class="titulo">Crédito (CxC)</div>
                    <div class="row"><span class="k">Folio</span><span class="v">{{ $cxc->folio }}</span></div>
                    <div class="row"><span class="k">Financiado</span><span class="v">{{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($cxc->importe_original_centavos)) }}</span></div>
                    <div class="row"><span class="k">Saldo</span><span class="v">{{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($cxc->saldo_centavos)) }}</span></div>
                    <div class="row"><span class="k">Vence</span><span class="v">{{ $cxc->fecha_vencimiento?->format('Y-m-d') }}</span></div>
                    <div class="row"><span class="k">Plazo</span><span class="v">{{ $cxc->dias_credito_aplicados }} día(s)</span></div>
                </div>
            @endif

            @if($venta->notas)
                <div class="notas">
                    <div class="k">Notas</div>
                    <div>{{ $venta->notas }}</div>
                </div>
            @endif

            <div class="pie">
                <span class="folio">{{ $venta->folio }}</span><br>
                Gracias por su compra
                @if($configuracion['ticket_pie'])
                    <br>{{ $configuracion['ticket_pie'] }}
                @endif
            </div>
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
