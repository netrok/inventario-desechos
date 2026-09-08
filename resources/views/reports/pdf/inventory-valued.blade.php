<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Inventario Valuado</title>

    <style>
        @page { margin: 14px 14px 30px 14px; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #111827;
        }

        .title { font-size: 15px; font-weight: 700; margin: 0; }
        .subtitle { margin: 3px 0 0 0; font-size: 10px; font-weight: 400; color: #111827; }
        .meta { margin: 2px 0 0 0; color: #6B7280; font-size: 8.5px; }
        .leyenda { margin: 6px 0 0 0; padding: 5px 7px; border: 1px solid #E5E7EB; background: #FFFBEB; border-radius: 6px; font-size: 8px; color: #92400E; }

        .filters-box {
            margin: 8px 0 8px 0;
            padding: 5px 7px;
            border: 1px solid #E5E7EB;
            background: #F9FAFB;
            border-radius: 8px;
            font-size: 8px;
            color: #374151;
        }
        .filters-title { font-weight: 700; margin-right: 6px; color: #111827; }
        .chip {
            display: inline-block;
            padding: 1px 7px;
            margin: 2px 3px 0 0;
            border: 1px solid #E5E7EB;
            background: #FFFFFF;
            border-radius: 999px;
            line-height: 1.4;
        }
        .chip strong { font-weight: 700; }

        .section-title { font-size: 11px; font-weight: 700; margin: 10px 0 5px 0; color: #111827; }

        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td {
            border: 1px solid #E5E7EB;
            padding: 4px;
            vertical-align: top;
            word-wrap: break-word;
        }
        th {
            background: #F3F4F6;
            font-size: 8px;
            text-transform: uppercase;
            color: #374151;
        }
        .num { text-align: right; }

        .kpi-grid { width: 100%; margin: 6px 0 0 0; }
        .kpi-grid td { border: 1px solid #E5E7EB; padding: 4px 6px; }

        .badge {
            display: inline-block;
            padding: 1px 5px;
            border: 1px solid #D1D5DB;
            border-radius: 4px;
            font-size: 8px;
            font-weight: 700;
        }
        .b-ok   { background: #ECFDF5; border-color: #A7F3D0; color: #047857; }
        .b-warn { background: #FFFBEB; border-color: #FDE68A; color: #B45309; }
        .b-info { background: #EFF6FF; border-color: #BFDBFE; color: #1D4ED8; }
        .b-gray { background: #F3F4F6; border-color: #E5E7EB; color: #374151; }
        .b-bad  { background: #FEF2F2; border-color: #FECACA; color: #B91C1C; }

        .footer { position: fixed; bottom: -8px; left: 0; right: 0; font-size: 8.5px; color: #6B7280; }
        .footer-left { position: absolute; left: 14px; }
        .footer-right { position: absolute; right: 14px; }
    </style>
</head>

<body>
@php
    $badgeClass = function ($estado) {
        return match ($estado) {
            'DISPONIBLE' => 'b-ok',
            'RESERVADO' => 'b-warn',
            'REPARACION', 'REPARACIÓN' => 'b-info',
            'DEVUELTO' => 'b-info',
            'BAJA' => 'b-bad',
            default => 'b-gray',
        };
    };
    $fmt = function ($v) {
        if ($v === null || $v === '') return '—';
        return '$' . \App\Support\Money::formatear(\App\Support\Money::aPrecio(\App\Support\Money::aCentavos($v)));
    };
@endphp

    <h1 class="title">Inventario valuado</h1>
    <p class="subtitle">Valuación comercial estimada a precio de venta</p>
    <p class="meta">
        Generado: {{ optional($generatedAt)->format('Y-m-d H:i') }}
        &nbsp;•&nbsp;
        Equipos: {{ $items->count() }}
    </p>

    <div class="leyenda">
        Este reporte utiliza el precio de venta actual registrado en cada equipo. No representa
        costo histórico, valor en libros ni valuación contable. Excluye el estado VENDIDO.
    </div>

    <div class="filters-box">
        <span class="filters-title">Filtros</span>
        <span class="chip">Código: <strong>{{ !empty($filters['codigo']) ? $filters['codigo'] : 'Todos' }}</strong></span>
        <span class="chip">Estado: <strong>{{ !empty($filters['estado']) ? $filters['estado'] : 'Todos' }}</strong></span>
        <span class="chip">Ubicación: <strong>{{ $filters['ubicacion_name'] ?? 'Todas' }}</strong></span>
        <span class="chip">Categoría: <strong>{{ $filters['categoria_name'] ?? 'Todas' }}</strong></span>
        <span class="chip">Marca: <strong>{{ !empty($filters['marca']) ? $filters['marca'] : 'Todas' }}</strong></span>
        <span class="chip">Modelo: <strong>{{ !empty($filters['modelo']) ? $filters['modelo'] : 'Todos' }}</strong></span>
        <span class="chip">Serie: <strong>{{ !empty($filters['serie']) ? $filters['serie'] : 'Todas' }}</strong></span>
        <span class="chip">Estado de precio: <strong>{{ $filters['estado_precio'] ?: 'Todos' }}</strong></span>
        <span class="chip">Precio mín.: <strong>{{ $filters['precio_min'] ?? '—' }}</strong></span>
        <span class="chip">Precio máx.: <strong>{{ $filters['precio_max'] ?? '—' }}</strong></span>
        @if(!empty($filters['alta_desde']) || !empty($filters['alta_hasta']))
            <span class="chip">Alta: <strong>{{ $filters['alta_desde']?->format('Y-m-d') ?? '…' }} a {{ $filters['alta_hasta']?->format('Y-m-d') ?? '…' }}</strong></span>
        @endif
    </div>

    <div class="section-title">Indicadores</div>
    <table class="kpi-grid">
        <tr>
            <td>Equipos actuales<br><strong>{{ $kpis['equipos'] }}</strong></td>
            <td>Con precio<br><strong>{{ $kpis['con_precio'] }}</strong></td>
            <td>Sin precio<br><strong>{{ $kpis['sin_precio'] }}</strong></td>
            <td>Precio cero<br><strong>{{ $kpis['precio_cero'] }}</strong></td>
            <td>Cobertura<br><strong>{{ number_format($kpis['cobertura'] * 100, 1) }}%</strong></td>
            <td>Valor comercial<br><strong>{{ $fmt($kpis['valor_comercial']) }}</strong></td>
        </tr>
        <tr>
            <td>Disponible/reservado<br><strong>{{ $fmt($kpis['valor_disponible_reservado']) }}</strong></td>
            <td>En revisión<br><strong>{{ $fmt($kpis['valor_revision']) }}</strong></td>
            <td>En baja<br><strong>{{ $fmt($kpis['valor_baja']) }}</strong></td>
            <td colspan="3"></td>
        </tr>
    </table>

    @foreach ([
        ['title' => 'Por estado', 'rows' => $agrupaciones['estado']],
        ['title' => 'Por categoría', 'rows' => $agrupaciones['categoria']],
        ['title' => 'Por ubicación', 'rows' => $agrupaciones['ubicacion']],
    ] as $block)
        <div class="section-title">{{ $block['title'] }}</div>
        <table>
            <thead>
                <tr>
                    <th style="width:45%;">Grupo</th>
                    <th style="width:15%;" class="num">Equipos</th>
                    <th style="width:15%;" class="num">Con precio</th>
                    <th style="width:25%;" class="num">Valor</th>
                </tr>
            </thead>
            <tbody>
            @forelse($block['rows'] as $r)
                <tr>
                    <td>{{ $r['grupo'] }}</td>
                    <td class="num">{{ $r['equipos'] }}</td>
                    <td class="num">{{ $r['con_precio'] }}</td>
                    <td class="num">{{ $fmt($r['valor']) }}</td>
                </tr>
            @empty
                <tr><td colspan="4">Sin datos</td></tr>
            @endforelse
            </tbody>
        </table>
    @endforeach

    <div class="section-title">Detalle</div>
    <table>
        <thead>
            <tr>
                <th style="width:60px;">Código</th>
                <th>Categoría</th>
                <th>Marca</th>
                <th>Modelo</th>
                <th style="width:70px;">Estado</th>
                <th>Ubicación</th>
                <th style="width:80px;" class="num">Precio de venta</th>
            </tr>
        </thead>

        <tbody>
        @foreach($items as $it)
            <tr>
                <td><strong>{{ $it->codigo }}</strong></td>
                <td>{{ $it->categoria?->nombre ?? '—' }}</td>
                <td>{{ $it->marca ?: '—' }}</td>
                <td>{{ $it->modelo ?: '—' }}</td>
                <td><span class="badge {{ $badgeClass($it->estado) }}">{{ $it->estado }}</span></td>
                <td>{{ $it->ubicacion?->nombre ?? '—' }}</td>
                <td class="num">
                    @if($it->precio === null)
                        Sin precio
                    @else
                        {{ $fmt($it->precio) }}
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="footer">
        <div class="footer-left">Inventario ReUse · Inventario valuado</div>
        <div class="footer-right">Página {PAGE_NUM} de {PAGE_COUNT}</div>
    </div>

</body>
</html>
