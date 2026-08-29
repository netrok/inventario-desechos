<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de Movimientos</title>

    <style>
        @page { margin: 18px 18px 34px 18px; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
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

        .footer { position: fixed; bottom: -10px; left: 0; right: 0; font-size: 9px; color: #6B7280; }
        .footer-left { position: absolute; left: 18px; }
        .footer-right { position: absolute; right: 18px; }
    </style>
</head>

<body>
@php
    $fmt = function ($date) {
        return $date ? $date->format('Y-m-d H:i') : '—';
    };
@endphp

    <h1 class="title">Reporte de Movimientos</h1>
    <p class="meta">
        Generado: {{ optional($generatedAt)->format('Y-m-d H:i') }}
        &nbsp;•&nbsp;
        Total: {{ $movimientos->count() }}
    </p>

    <div class="filters-box">
        <span class="filters-title">Filtros</span>

        <span class="chip">Desde: <strong>{{ $filters['desde']?->format('Y-m-d') ?? '—' }}</strong></span>
        <span class="chip">Hasta: <strong>{{ $filters['hasta']?->format('Y-m-d') ?? '—' }}</strong></span>
        <span class="chip">Usuario: <strong>{{ $filters['usuario_name'] ?? 'Todos' }}</strong></span>
        <span class="chip">Tipo: <strong>{{ $filters['tipo'] ?? 'Todos' }}</strong></span>
        <span class="chip">Código Item: <strong>{{ !empty($filters['codigo']) ? $filters['codigo'] : 'Todos' }}</strong></span>
        <span class="chip">Ubicación origen: <strong>{{ $filters['ubicacion_origen_name'] ?? 'Todas' }}</strong></span>
        <span class="chip">Ubicación destino: <strong>{{ $filters['ubicacion_destino_name'] ?? 'Todas' }}</strong></span>
    </div>

    <div class="hr"></div>

    <table>
        <thead>
            <tr>
                <th style="width:86px;">Fecha</th>
                <th style="width:86px;">Código</th>
                <th>Tipo</th>
                <th>Usuario</th>
                <th>Estado anterior</th>
                <th>Estado nuevo</th>
                <th>Ubicación ant.</th>
                <th>Ubicación nueva</th>
                <th>Notas</th>
            </tr>
        </thead>

        <tbody>
        @foreach($movimientos as $m)
            <tr>
                <td>{{ $fmt($m->created_at) }}</td>
                <td><strong>{{ $m->item?->codigo ?? '#' . $m->item_id }}</strong></td>
                <td>{{ $m->tipo }}</td>
                <td>{{ $m->user?->name ?? '—' }}</td>
                <td>{{ $m->de_estado ?? '—' }}</td>
                <td>{{ $m->a_estado ?? '—' }}</td>
                <td>{{ $m->deUbicacion?->nombre ?? '—' }}</td>
                <td>{{ $m->aUbicacion?->nombre ?? '—' }}</td>
                <td>{{ $m->notas ?: '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="footer">
        <div class="footer-left">Inventario Desechos · Reporte de Movimientos</div>
        <div class="footer-right">Página {PAGE_NUM} de {PAGE_COUNT}</div>
    </div>

</body>
</html>