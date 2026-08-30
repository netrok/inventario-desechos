<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Corte de Caja {{ $d['folio'] }}</title>
    <style>
        @page { margin: 18px 18px 34px 18px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #111827; }
        .title { font-size: 16px; font-weight: 700; margin: 0; }
        .meta { margin: 4px 0 0 0; color: #6B7280; font-size: 9px; }
        .hr { border-top: 1px solid #E5E7EB; margin: 10px 0 12px 0; }

        .card { border: 1px solid #E5E7EB; border-radius: 8px; margin-bottom: 10px; overflow: hidden; }
        .card-title {
            background: #F3F4F6; padding: 6px 10px; font-size: 10px;
            text-transform: uppercase; font-weight: 700; color: #374151;
        }
        .card-body { padding: 8px 10px; }

        table { width: 100%; border-collapse: collapse; }
        td { padding: 3px 6px; vertical-align: top; }
        .k { color: #374151; width: 220px; font-size: 10px; }
        .v { font-weight: 700; font-size: 10.5px; }

        .pair { display: flex; justify-content: space-between; align-items: center; padding: 3px 6px; }
        .pair .k { width: auto; }
        .grande { font-size: 14px; font-weight: 800; }
        .pos { color: #065F46; }
        .neg { color: #B45309; }

        table.den { width: 100%; border-collapse: collapse; }
        table.den th, table.den td { border: 1px solid #E5E7EB; padding: 4px 6px; font-size: 9px; }
        table.den th { background: #F3F4F6; text-transform: uppercase; color: #374151; }

        .foot { margin-top: 18px; text-align: center; color: #9CA3AF; font-size: 8px; }
    </style>
</head>
<body>
    <h1 class="title">Corte de Caja</h1>
    <p class="meta">
        {{ $d['caja_codigo'] }} · {{ $d['caja_nombre'] }} · Sesión {{ $d['folio'] }} · Generado {{ now()->format('d/m/Y H:i') }}
    </p>

    <div class="hr"></div>

    <div class="card">
        <div class="card-title">Identificación</div>
        <div class="card-body">
            <table>
                <tr><td class="k">Caja</td><td class="v">{{ $d['caja_codigo'] }} — {{ $d['caja_nombre'] }}</td></tr>
                <tr><td class="k">Folio de sesión</td><td class="v">{{ $d['folio'] }}</td></tr>
                <tr><td class="k">Operador</td><td class="v">{{ $d['operador'] }}</td></tr>
                <tr><td class="k">Cerrado por</td><td class="v">{{ $d['cerrado_por'] ?? '—' }}</td></tr>
                <tr><td class="k">Apertura</td><td class="v">{{ $d['apertura']?->format('d/m/Y H:i:s') ?? '—' }}</td></tr>
                <tr><td class="k">Cierre</td><td class="v">{{ $d['cierre']?->format('d/m/Y H:i:s') ?? '—' }}</td></tr>
                <tr><td class="k">Fondo inicial</td><td class="v">${{ $d['fondo_inicial'] }}</td></tr>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-title">Ventas y pagos</div>
        <div class="card-body">
            @foreach([
                'Ventas totales' => $d['ventas_totales'],
                'Pagos Efectivo' => $d['pagos_por_metodo']['EFECTIVO'],
                'Pagos Tarjeta' => $d['pagos_por_metodo']['TARJETA'],
                'Pagos Transferencia' => $d['pagos_por_metodo']['TRANSFERENCIA'],
            ] as $label => $valor)
                <div class="pair"><span class="k">{{ $label }}</span><span class="v">${{ $valor }}</span></div>
            @endforeach
        </div>
    </div>

    <div class="card">
        <div class="card-title">Efectivo detallado</div>
        <div class="card-body">
            <div class="pair"><span class="k">Efectivo recibido (bruto)</span><span class="v">${{ $d['efectivo_recibido_bruto'] }}</span></div>
            <div class="pair"><span class="k">Cambio entregado</span><span class="v neg">${{ $d['cambio_entregado'] }}</span></div>
            <div class="pair"><span class="k">Efectivo neto aplicado</span><span class="v pos">${{ $d['efectivo_neto'] }}</span></div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">Operaciones</div>
        <div class="card-body">
            <div class="pair"><span class="k">Entradas manuales</span><span class="v pos">+${{ $d['entradas_manuales'] }}</span></div>
            <div class="pair"><span class="k">Retiros</span><span class="v neg">-${{ $d['retiros'] }}</span></div>
            <div class="pair"><span class="k">Reembolsos en efectivo</span><span class="v neg">-${{ $d['reembolsos'] }}</span></div>
            <div class="pair"><span class="k">Ajustes (entrada)</span><span class="v pos">+${{ $d['ajustes_entrada'] }}</span></div>
            <div class="pair"><span class="k">Ajustes (salida)</span><span class="v neg">-${{ $d['ajustes_salida'] }}</span></div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">Arqueo y cierre</div>
        <div class="card-body">
            <div class="pair"><span class="k">Efectivo esperado</span><span class="v">${{ $d['esperado'] }}</span></div>
            <div class="pair"><span class="k">Efectivo contado</span><span class="v">${{ $d['contado'] ?? '—' }}</span></div>
            <div class="pair"><span class="k">Diferencia</span><span class="v {{ (float)$d['diferencia'] < 0 ? 'neg' : ((float)$d['diferencia'] > 0 ? 'pos' : '') }}">${{ $d['diferencia'] ?? '—' }}</span></div>
            @if($d['observaciones_cierre'])
                <div class="pair"><span class="k">Observaciones de cierre</span><span class="v">{{ $d['observaciones_cierre'] }}</span></div>
            @endif
        </div>
    </div>

    @if(! empty($d['denominaciones']))
        <div class="card">
            <div class="card-title">Arqueo por denominaciones</div>
            <div class="card-body">
                <table class="den">
                    <thead>
                        <tr><th>Denominación</th><th>Cantidad</th><th>Subtotal</th></tr>
                    </thead>
                    <tbody>
                        @foreach($d['denominaciones'] as $den)
                            <tr>
                                <td>${{ $den->denominacion }}</td>
                                <td>{{ $den->cantidad }}</td>
                                <td>${{ $den->subtotal }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="foot">Documento generado por Inventario ReUse · Corte de caja {{ $d['folio'] }}</div>
</body>
</html>
