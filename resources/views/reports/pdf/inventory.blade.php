<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de Inventario</title>

    <style>
        @page { margin: 18px 18px 34px 18px; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.5px;
            color: #111827;
        }

        .title { font-size: 16px; font-weight: 700; margin: 0; }
        .meta { margin: 4px 0 0 0; color: #6B7280; font-size: 9px; }

        .filters-box {
            margin: 8px 0 10px 0;
            padding: 6px 8px;
            border: 1px solid #E5E7EB;
            background: #F9FAFB;
            border-radius: 8px;
            font-size: 9px;
            color: #374151;
        }
        .filters-title { font-weight: 700; margin-right: 6px; color: #111827; }
        .chip {
            display: inline-block;
            padding: 2px 8px;
            margin: 3px 4px 0 0;
            border: 1px solid #E5E7EB;
            background: #FFFFFF;
            border-radius: 999px;
            line-height: 1.4;
        }
        .chip strong { font-weight: 700; }

        .hr { border-top: 1px solid #E5E7EB; margin: 10px 0 12px 0; }

        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td {
            border: 1px solid #E5E7EB;
            padding: 5px;
            vertical-align: top;
            word-wrap: break-word;
        }
        th {
            background: #F3F4F6;
            font-size: 9px;
            text-transform: uppercase;
            color: #374151;
        }

        .badge {
            display: inline-block;
            padding: 2px 6px;
            border: 1px solid #D1D5DB;
            border-radius: 4px;
            font-size: 9px;
            font-weight: 700;
        }
        .b-ok   { background: #ECFDF5; border-color: #A7F3D0; color: #047857; }
        .b-warn { background: #FFFBEB; border-color: #FDE68A; color: #B45309; }
        .b-info { background: #EFF6FF; border-color: #BFDBFE; color: #1D4ED8; }
        .b-gray { background: #F3F4F6; border-color: #E5E7EB; color: #374151; }
        .b-bad  { background: #FEF2F2; border-color: #FECACA; color: #B91C1C; }

        .footer { position: fixed; bottom: -10px; left: 0; right: 0; font-size: 9px; color: #6B7280; }
        .footer-left { position: absolute; left: 18px; }
        .footer-right { position: absolute; right: 18px; }
    </style>
</head>

<body>
@php
    $badgeClass = function ($estado) {
        return match ($estado) {
            'DISPONIBLE' => 'b-ok',
            'RESERVADO' => 'b-warn',
            'REPARACION', 'REPARACIÓN' => 'b-info',
            'VENDIDO' => 'b-gray',
            'BAJA' => 'b-bad',
            default => 'b-gray',
        };
    };
@endphp

    <h1 class="title">Reporte de Inventario</h1>
    <p class="meta">
        Generado: {{ optional($generatedAt)->format('Y-m-d H:i') }}
        &nbsp;•&nbsp;
        Total: {{ $items->count() }}
    </p>

    <div class="filters-box">
        <span class="filters-title">Filtros</span>

        <span class="chip">Código: <strong>{{ !empty($filters['codigo']) ? $filters['codigo'] : 'Todos' }}</strong></span>
        <span class="chip">Estado: <strong>{{ !empty($filters['estado']) ? $filters['estado'] : 'Todos' }}</strong></span>
        <span class="chip">Ubicación: <strong>{{ $filters['ubicacion_name'] ?? 'Todas' }}</strong></span>
        <span class="chip">Categoría: <strong>{{ $filters['categoria_name'] ?? 'Todas' }}</strong></span>
        <span class="chip">Marca: <strong>{{ !empty($filters['marca']) ? $filters['marca'] : 'Todas' }}</strong></span>
        <span class="chip">Modelo: <strong>{{ !empty($filters['modelo']) ? $filters['modelo'] : 'Todos' }}</strong></span>
        <span class="chip">Serie: <strong>{{ !empty($filters['serie']) ? $filters['serie'] : 'Todas' }}</strong></span>
        @if(!empty($filters['alta_desde']) || !empty($filters['alta_hasta']))
            <span class="chip">Alta: <strong>{{ $filters['alta_desde']?->format('Y-m-d') ?? '…' }} a {{ $filters['alta_hasta']?->format('Y-m-d') ?? '…' }}</strong></span>
        @endif
    </div>

    <div class="hr"></div>

    <table>
        <thead>
            <tr>
                <th style="width:70px;">Código</th>
                <th>Categoría</th>
                <th>Marca</th>
                <th>Modelo</th>
                <th>Serie</th>
                <th style="width:70px;">Estado</th>
                <th>Ubicación</th>
                <th style="width:70px;">Fecha de alta</th>
                <th>Notas</th>
            </tr>
        </thead>

        <tbody>
        @foreach($items as $it)
            <tr>
                <td><strong>{{ $it->codigo }}</strong></td>
                <td>{{ $it->categoria?->nombre ?? '—' }}</td>
                <td>{{ $it->marca ?: '—' }}</td>
                <td>{{ $it->modelo ?: '—' }}</td>
                <td>{{ $it->serie ?: '—' }}</td>
                <td><span class="badge {{ $badgeClass($it->estado) }}">{{ $it->estado }}</span></td>
                <td>{{ $it->ubicacion?->nombre ?? '—' }}</td>
                <td>{{ optional($it->created_at)->format('Y-m-d') ?: '—' }}</td>
                <td>{{ $it->notas ?: '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="footer">
        <div class="footer-left">Inventario Desechos · Reporte de Inventario</div>
        <div class="footer-right">Página {PAGE_NUM} de {PAGE_COUNT}</div>
    </div>

</body>
</html>